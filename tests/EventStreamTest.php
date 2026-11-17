<?php

declare(strict_types=1);

namespace BillionSend\Tests;

use BillionSend\Exception\APIConnectionError;
use BillionSend\Exception\AuthenticationError;
use BillionSend\Exception\BillionSendError;
use BillionSend\InboxStreamEvent;
use BillionSend\Request;
use BillionSend\StreamGapEvent;

final class EventStreamTest extends TestCase
{
    private static function event(string $id, string $name, string $inbox = 'i1'): string
    {
        return "id: $id\nevent: $name\ndata: {\"type\":\"$name\",\"created_at\":\"2026-11-17T00:00:00Z\",\"inbox_id\":\"$inbox\",\"data\":{\"thread_id\":\"t1\"}}\n\n";
    }

    /**
     * @return list<string>
     */
    private static function collect(iterable $stream): array
    {
        $got = [];
        foreach ($stream as $item) {
            $got[] = $item instanceof StreamGapEvent ? "gap:{$item->reason}" : "{$item->id}:{$item->event}:{$item->payload()['thread_id']}";
        }
        return $got;
    }

    public function testStreamsTypedEventsAndResumesAfterADrop(): void
    {
        [$client, $t] = $this->client(fn (int $n) => match ($n) {
            1 => FakeTransport::stream(": keep-alive\n\nretry: 250\n" . self::event('1', 'inbox.email.received') . self::event('2', 'inbox.thread.created')),
            2 => FakeTransport::stream("event: stream.gap\ndata: {\"reason\":\"position expired\"}\n\n" . self::event('3', 'email.received') . 'data: unterminated'),
            default => FakeTransport::json(503, '{"name":"unavailable","message":"later"}'),
        }, ['max_retries' => 0]);
        $stream = $client->inboxes->events->stream(['types' => 'inbox.email.received'], ['last_event_id' => '0', 'max_reconnects' => 1]);
        $got = [];
        try {
            foreach ($stream as $item) {
                $got[] = $item instanceof StreamGapEvent ? "gap:{$item->reason}" : "{$item->id}:{$item->event}:{$item->payload()['thread_id']}:{$item->inboxId()}";
            }
            $this->fail('no error');
        } catch (APIConnectionError) {
        }
        $this->assertSame(['1:inbox.email.received:t1:i1', '2:inbox.thread.created:t1:i1', 'gap:position expired', '3:email.received:t1:i1'], $got);
        $this->assertSame('0', $t->requests[0]->headers['last-event-id']);
        $this->assertSame('2', $t->requests[1]->headers['last-event-id']);
        $this->assertSame('3', $t->requests[2]->headers['last-event-id']);
        $this->assertSame('text/event-stream', $t->requests[0]->headers['accept']);
        $this->assertStringEndsWith('/inboxes/events?types=inbox.email.received', $t->requests[0]->url);
        $this->assertTrue($t->streams[0]);
        $this->assertSame(0.25, $this->waits[0]);
        $this->assertSame('3', $stream->lastEventId());
    }

    public function testHandsOutAGapOncePerPosition(): void
    {
        $gap = "event: stream.gap\ndata: {\"reason\":\"old\"}\n\n";
        [$client] = $this->client(fn (int $n) => FakeTransport::stream($n <= 2 ? $gap : $gap . self::event('9', 'inbox.updated')));
        $stream = $client->inboxes->events->stream([], ['max_reconnects' => 3]);
        $got = [];
        foreach ($stream as $item) {
            $got[] = $item instanceof InboxStreamEvent ? $item->id : 'gap';
            if ($item instanceof InboxStreamEvent) {
                $stream->close();
            }
        }
        $this->assertSame(['gap', '9'], $got);
    }

    public function testOneInboxsStreamEndsWithItsDeletion(): void
    {
        [$client, $t] = $this->client(fn () => FakeTransport::stream(self::event('1', 'inbox.email.received') . self::event('2', 'inbox.deleted') . self::event('3', 'inbox.updated')));
        $got = self::collect($client->inboxes->events->streamInbox('support@example.com'));
        $this->assertSame(['1:inbox.email.received:t1', '2:inbox.deleted:t1'], $got);
        $this->assertCount(1, $t->requests);
    }

    public function testStopsOnAnErrorARetryCannotFix(): void
    {
        [$client] = $this->client(fn (int $n) => $n === 1 ? FakeTransport::stream(self::event('1', 'inbox.updated')) : FakeTransport::json(401, '{"name":"invalid_api_key","message":"revoked"}'));
        $this->expectException(AuthenticationError::class);
        self::collect($client->inboxes->events->stream());
    }

    public function testRefusesAnAnswerThatIsNotAStream(): void
    {
        [$client] = $this->client(fn () => FakeTransport::json(200, '{}'));
        $this->expectException(BillionSendError::class);
        $this->expectExceptionMessage('Expected an event stream');
        $client->inboxes->events->stream();
    }

    public function testWithoutReconnectEndsWhenTheConnectionDrops(): void
    {
        [$client, $t] = $this->client(fn () => FakeTransport::stream(self::event('1', 'inbox.updated')));
        $this->assertSame(['1:inbox.updated:t1'], self::collect($client->inboxes->events->stream([], ['reconnect' => false])));
        $this->assertCount(1, $t->requests);
    }
}
