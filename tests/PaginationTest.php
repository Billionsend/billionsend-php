<?php

declare(strict_types=1);

namespace BillionSend\Tests;

use BillionSend\Exception\PermissionDeniedError;
use BillionSend\Request;

final class PaginationTest extends TestCase
{
    private static function query(Request $r): array
    {
        parse_str((string) parse_url($r->url, PHP_URL_QUERY), $q);
        return $q;
    }

    public function testListAllWalksForwardPageByPage(): void
    {
        [$client, $t] = $this->client(fn (int $n, Request $r) => match (self::query($r)['after'] ?? '') {
            '' => FakeTransport::json(200, '{"object":"list","has_more":true,"data":[{"id":"a"},{"id":"b"}]}'),
            'b' => FakeTransport::json(200, '{"object":"list","has_more":true,"data":[{"id":"c"}]}'),
            default => FakeTransport::json(200, '{"object":"list","has_more":false,"data":[{"id":"d"}]}'),
        });
        $ids = [];
        foreach ($client->emails->listAll(['limit' => 2]) as $email) {
            $ids[] = $email['id'];
        }
        $this->assertSame(['a', 'b', 'c', 'd'], $ids);
        $this->assertCount(3, $t->requests);
        $this->assertSame(['limit' => '2', 'after' => 'c'], self::query($t->requests[2]));
    }

    public function testListAllWalksBackwardFromBefore(): void
    {
        [$client, $t] = $this->client(fn (int $n, Request $r) => (self::query($r)['before'] ?? '') === 'x'
            ? FakeTransport::json(200, '{"object":"list","has_more":true,"data":[{"id":"v"},{"id":"w"}]}')
            : FakeTransport::json(200, '{"object":"list","has_more":false,"data":[{"id":"u"}]}'));
        $ids = [];
        foreach ($client->emails->listAll(['before' => 'x', 'after' => 'ignored']) as $email) {
            $ids[] = $email['id'];
        }
        $this->assertSame(['v', 'w', 'u'], $ids);
        $this->assertSame(['before' => 'v'], self::query($t->requests[1]));
    }

    public function testAFailedPageThrowsAndTheWalkIsLazy(): void
    {
        [$client, $t] = $this->client(fn (int $n) => $n === 1
            ? FakeTransport::json(200, '{"object":"list","has_more":true,"data":[{"id":"a"},{"id":"b"}]}')
            : FakeTransport::json(403, '{"name":"restricted_api_key","message":"no"}'), ['max_retries' => 0]);
        $seen = [];
        try {
            foreach ($client->emails->listAll() as $email) {
                $seen[] = $email['id'];
            }
            $this->fail('no error');
        } catch (PermissionDeniedError) {
        }
        $this->assertSame(['a', 'b'], $seen);
        // Nothing is fetched until the walk starts.
        $client->inboxes->threads->searchAll('support@example.com', ['query' => 'x']);
        $this->assertCount(2, $t->requests);
    }
}
