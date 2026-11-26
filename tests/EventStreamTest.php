<?php

declare(strict_types=1);

namespace BillionSend\Tests;

use BillionSend\Exception\APIConnectionError;
use BillionSend\Exception\APIError;
use BillionSend\Exception\AuthenticationError;
use BillionSend\Exception\BillionSendError;
use BillionSend\Exception\RateLimitError;
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
        } catch (APIError) {
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

    public function testKeepsReconnectingAQuietStreamTheServerCloses(): void
    {
        [$client, $t] = $this->client(fn (int $n) => FakeTransport::stream($n <= 12 ? ": keepalive\n\n" : self::event('1', 'inbox.updated')), ['max_retries' => 0]);
        $stream = $client->inboxes->events->stream([], ['max_reconnects' => 2]);
        $got = [];
        foreach ($stream as $item) {
            $got[] = $item->id;
            $stream->close();
        }
        $this->assertSame(['1'], $got);
        $this->assertCount(13, $t->requests);
    }

    public function testGivesUpAfterTheReconnectsThatFailInARow(): void
    {
        [$client, $t] = $this->client(fn (int $n) => $n === 1 ? FakeTransport::stream(": keepalive\n\n") : FakeTransport::json(503, '{"name":"unavailable","message":"later"}'), ['max_retries' => 0]);
        try {
            self::collect($client->inboxes->events->stream([], ['max_reconnects' => 3]));
            $this->fail('no error');
        } catch (APIError $e) {
            $this->assertSame('unavailable', $e->getErrorName());
        }
        $this->assertCount(4, $t->requests);
    }

    public function testWaitsOutARetryAfterAndThrowsTheAPIsAnswerWhenTheReconnectsRunOut(): void
    {
        [$client, $t] = $this->client(fn (int $n) => $n === 1
            ? FakeTransport::stream(": keepalive\n\n")
            : FakeTransport::json(429, '{"name":"too_many_streams","message":"later"}', ['retry-after' => '7']), ['max_retries' => 0]);
        try {
            self::collect($client->inboxes->events->stream([], ['max_reconnects' => 2]));
            $this->fail('no error');
        } catch (RateLimitError $e) {
            $this->assertSame('too_many_streams', $e->getErrorName());
        }
        $this->assertCount(3, $t->requests);
        // The first wait is the default second; the next is the API's 7.
        $this->assertSame([1.0, 7.0], $this->waits);
    }

    public function testThrowsARetryAfterLongerThanAMinuteAtOnce(): void
    {
        [$client, $t] = $this->client(fn (int $n) => $n === 1
            ? FakeTransport::stream(": keepalive\n\n")
            : FakeTransport::json(503, '{"name":"unavailable","message":"later"}', ['retry-after' => '120']), ['max_retries' => 0]);
        try {
            self::collect($client->inboxes->events->stream([], ['max_reconnects' => 5]));
            $this->fail('no error');
        } catch (APIError $e) {
            $this->assertSame(120.0, $e->getRetryAfter());
        }
        $this->assertCount(2, $t->requests);
    }

    public function testGivesUpWhenTheAPICannotBeReached(): void
    {
        [$client, $t] = $this->client(function (int $n) {
            if ($n === 1) {
                return FakeTransport::stream(": keepalive\n\n");
            }
            throw new APIConnectionError('Network error: refused');
        }, ['max_retries' => 0]);
        try {
            self::collect($client->inboxes->events->stream([], ['max_reconnects' => 2]));
            $this->fail('no error');
        } catch (APIConnectionError $e) {
            $this->assertStringContainsString('gave up after 2 reconnects', $e->getMessage());
        }
        $this->assertCount(3, $t->requests);
    }

    public function testResumesFromAnIdSentWithoutData(): void
    {
        [$client, $t] = $this->client(fn (int $n) => match ($n) {
            1 => FakeTransport::stream("id: 40\n\n: keepalive\n\n"),
            2 => FakeTransport::stream("id: 41\n\nid: 99\n"),
            3 => FakeTransport::stream(self::event('42', 'inbox.updated') . "id: 43\n\nid: 4"),
            default => FakeTransport::json(401, '{"name":"invalid_api_key","message":"revoked"}'),
        });
        $stream = $client->inboxes->events->stream();
        $got = [];
        try {
            foreach ($stream as $item) {
                $got[] = $item->id;
            }
            $this->fail('no error');
        } catch (AuthenticationError) {
        }
        $this->assertSame(['42'], $got);
        $this->assertArrayNotHasKey('last-event-id', $t->requests[0]->headers);
        $this->assertSame('40', $t->requests[1]->headers['last-event-id']);
        // The id of a block cut off midway never counts.
        $this->assertSame('41', $t->requests[2]->headers['last-event-id']);
        $this->assertSame('43', $t->requests[3]->headers['last-event-id']);
    }

    public function testRefusesAResumeIdHttpCannotCarry(): void
    {
        [$client, $t] = $this->client(fn () => FakeTransport::stream(''));
        try {
            $client->inboxes->events->stream([], ['last_event_id' => "1\nx: y"]);
            $this->fail('it went out');
        } catch (BillionSendError $e) {
            $this->assertSame('invalid_header', $e->getErrorName());
        }
        $this->assertSame([], $t->requests);
    }

    public function testWithoutReconnectEndsWhenTheConnectionDrops(): void
    {
        [$client, $t] = $this->client(fn () => FakeTransport::stream(self::event('1', 'inbox.updated')));
        $this->assertSame(['1:inbox.updated:t1'], self::collect($client->inboxes->events->stream([], ['reconnect' => false])));
        $this->assertCount(1, $t->requests);
    }
}
