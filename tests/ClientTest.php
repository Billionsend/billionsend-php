<?php

declare(strict_types=1);

namespace BillionSend\Tests;

use BillionSend\Client;
use BillionSend\Core;
use BillionSend\Exception\APIConnectionError;
use BillionSend\Exception\APIError;
use BillionSend\Exception\APITimeoutError;
use BillionSend\Exception\AuthenticationError;
use BillionSend\Exception\BillionSendError;
use BillionSend\Exception\ConflictError;
use BillionSend\Exception\NotFoundError;
use BillionSend\Exception\PermissionDeniedError;
use BillionSend\Exception\RateLimitError;
use BillionSend\Exception\ValidationError;
use BillionSend\Request;
use BillionSend\Response;

final class ClientTest extends TestCase
{
    private const SEND = ['from' => 'Acme <hello@acme.dev>', 'to' => ['ana@example.com'], 'subject' => 'Hi', 'html' => '<p>Hi</p>'];

    public function testReadsTheKeyAndBaseUrlFromTheEnvironment(): void
    {
        putenv('BILLIONSEND_API_KEY=bs_env');
        putenv('BILLIONSEND_BASE_URL=https://api.example.test/');
        try {
            $transport = new FakeTransport(fn () => FakeTransport::json(200, '{"object":"list","data":[],"has_more":false}'));
            (new Client(null, ['transport' => $transport]))->domains->list();
            $r = $transport->requests[0];
            $this->assertSame('https://api.example.test/domains', $r->url);
            $this->assertSame('Bearer bs_env', $r->headers['authorization']);
            $this->assertStringStartsWith('billionsend-php/', $r->headers['user-agent']);
        } finally {
            putenv('BILLIONSEND_API_KEY');
            putenv('BILLIONSEND_BASE_URL');
        }
    }

    public function testRefusesToCallWithoutAKey(): void
    {
        putenv('BILLIONSEND_API_KEY');
        $transport = new FakeTransport(fn () => FakeTransport::json(200, '{}'));
        try {
            (new Client(null, ['transport' => $transport]))->emails->get('e1');
            $this->fail('no error');
        } catch (AuthenticationError $e) {
            $this->assertSame('missing_api_key', $e->getErrorName());
            $this->assertNull($e->getStatusCode());
            $this->assertSame([], $transport->requests);
        }
    }

    /**
     * @return iterable<string, array{int, class-string<BillionSendError>}>
     */
    public static function statuses(): iterable
    {
        yield '400' => [400, ValidationError::class];
        yield '422' => [422, ValidationError::class];
        yield '401' => [401, AuthenticationError::class];
        yield '403' => [403, PermissionDeniedError::class];
        yield '404' => [404, NotFoundError::class];
        yield '409' => [409, ConflictError::class];
        yield '429' => [429, RateLimitError::class];
        yield '503' => [503, APIError::class];
        yield '418' => [418, BillionSendError::class];
    }

    /**
     * @dataProvider statuses
     * @param class-string<BillionSendError> $class
     */
    public function testMapsEachStatusToItsErrorClass(int $status, string $class): void
    {
        [$client] = $this->client(fn () => FakeTransport::json($status, '{"statusCode":0,"name":"some_error","message":"Nope"}', ['x-request-id' => 'req_1', 'retry-after' => '120']), ['max_retries' => 0]);
        try {
            $client->emails->get('e1');
            $this->fail('no error');
        } catch (BillionSendError $e) {
            $this->assertSame($class, $e::class);
            $this->assertSame(['statusCode' => $status, 'name' => 'some_error', 'message' => 'Nope', 'requestId' => 'req_1'], $e->toArray());
            if ($e instanceof RateLimitError) {
                $this->assertSame(120.0, $e->getRetryAfter());
            }
        }
    }

    public function testKeepsTheBodyWhenItIsNotAnError(): void
    {
        [$client] = $this->client(fn () => new Response(502, [], 'bad gateway'), ['max_retries' => 0]);
        try {
            $client->emails->get('e1');
            $this->fail('no error');
        } catch (APIError $e) {
            $this->assertSame('application_error', $e->getErrorName());
            $this->assertSame('bad gateway', $e->getMessage());
        }
    }

    public function testRetriesASafeRequestWithBackoff(): void
    {
        [$client, $t] = $this->client(fn (int $n) => $n < 3 ? FakeTransport::json(500, '{"name":"internal_error","message":"boom"}') : FakeTransport::json(200, '{"id":"e1"}'));
        $this->assertSame(['id' => 'e1'], $client->emails->get('e1'));
        $this->assertCount(3, $t->requests);
        $this->assertSame([0.5, 1.0], $this->waits);
    }

    public function testBacksOffNoLongerThanEightSeconds(): void
    {
        [$client] = $this->client(fn () => FakeTransport::json(200, '{}'));
        $core = $client->core();
        $got = array_map(fn ($a) => $core->retryDelay($a, null), range(0, 5));
        $this->assertSame([0.5, 1.0, 2.0, 4.0, 8.0, 8.0], $got);
        $core->random = static fn (): float => 1.0;
        $this->assertSame(0.375, $core->retryDelay(0, null));
        $this->assertNull($core->retryDelay(0, '61'));
        $this->assertSame(3.0, $core->retryDelay(0, '3'));
        $this->assertEqualsWithDelta(10.0, $core->retryDelay(0, gmdate('D, d M Y H:i:s \G\M\T', 1_000_010), 1_000_000.0), 0.001);
    }

    public function testHonoursRetryAfterAndGivesUpPastSixtySeconds(): void
    {
        [$client, $t] = $this->client(fn (int $n) => FakeTransport::json(429, '{"name":"rate_limit_exceeded","message":"slow down"}', ['retry-after' => $n === 1 ? '3' : '61']));
        try {
            $client->emails->get('e1');
            $this->fail('no error');
        } catch (RateLimitError $e) {
            $this->assertSame(61.0, $e->getRetryAfter());
        }
        $this->assertCount(2, $t->requests);
        $this->assertSame([3.0], $this->waits);
    }

    public function testNeverRetriesAPostWithoutAKey(): void
    {
        [$client, $t] = $this->client(fn () => FakeTransport::json(503, '{"name":"unavailable","message":"later"}'));
        try {
            $client->domains->create(['name' => 'acme.dev']);
            $this->fail('no error');
        } catch (APIError) {
        }
        $this->assertCount(1, $t->requests);
    }

    public function testRetriesA429ForEveryRequest(): void
    {
        [$client, $t] = $this->client(fn (int $n) => $n === 1
            ? FakeTransport::json(429, '{"name":"rate_limit_exceeded","message":"slow"}')
            : FakeTransport::json(200, '{"id":"d1"}'));
        $this->assertSame(['id' => 'd1'], $client->domains->create(['name' => 'acme.dev']));
        $this->assertCount(2, $t->requests);
        $this->assertSame([0.5], $this->waits);
    }

    public function testRefusesAPathParameterAUrlWouldFoldAway(): void
    {
        [$client, $t] = $this->client(fn () => FakeTransport::json(200, '{}'));
        foreach (['.', '..'] as $id) {
            try {
                $client->emails->get($id);
                $this->fail('it went out');
            } catch (BillionSendError $e) {
                $this->assertStringContainsString('cannot be', $e->getMessage());
            }
        }
        $this->assertSame([], $t->requests);
    }

    public function testTrimsTheKeyAndRefusesAControlCharacterInIt(): void
    {
        $transport = new FakeTransport(fn () => FakeTransport::json(200, '{}'));
        (new Client(" bs_x\n", ['transport' => $transport]))->domains->list();
        $this->assertSame('Bearer bs_x', $transport->requests[0]->headers['authorization']);
        try {
            (new Client(" \n", ['transport' => $transport]))->domains->list();
            $this->fail('no error');
        } catch (AuthenticationError $e) {
            $this->assertSame('missing_api_key', $e->getErrorName());
        }
        $this->expectException(\InvalidArgumentException::class);
        new Client("bs_x\r\nx-other: 1");
    }

    public function testRefusesAHeaderHttpCannotCarry(): void
    {
        [$client, $t] = $this->client(fn () => FakeTransport::json(200, '{}'));
        foreach ([
            ['idempotency_key' => 'welcome/订单-1'],
            ['idempotency_key' => "a\nb"],
            ['idempotency_key' => "a\n"],
            ['headers' => ['x-note' => "a\r\nx-other: 1"]],
            ['headers' => ["x-note\r\n" => 'a']],
        ] as $options) {
            try {
                $client->emails->send(self::SEND, $options);
                $this->fail('it went out');
            } catch (BillionSendError $e) {
                $this->assertSame('invalid_header', $e->getErrorName());
                $this->assertStringNotContainsString('订单', $e->getMessage());
            }
        }
        $this->assertSame([], $t->requests);
        $this->assertSame([], $this->waits);
    }

    public function testLeavesAnEmptyListOutOfTheQuery(): void
    {
        [$client, $t] = $this->client(fn () => FakeTransport::json(200, '{}'));
        $client->emails->metrics->get(['metrics' => [], 'timezone' => 'UTC']);
        $this->assertSame('https://api.test/emails/metrics?timezone=UTC', $t->requests[0]->url);
    }

    public function testASendCarriesOneKeyAcrossItsRetries(): void
    {
        [$client, $t] = $this->client(fn (int $n) => match ($n) {
            1 => FakeTransport::json(409, '{"name":"concurrent_idempotent_requests","message":"in progress"}'),
            2 => FakeTransport::json(500, '{"name":"internal_error","message":"boom"}'),
            default => FakeTransport::json(200, '{"id":"e1"}'),
        });
        $this->assertSame(['id' => 'e1'], $client->emails->send(self::SEND));
        $this->assertCount(3, $t->requests);
        $key = $t->requests[0]->headers['idempotency-key'];
        $this->assertMatchesRegularExpression(self::UUID_V4, $key);
        foreach ($t->requests as $r) {
            $this->assertSame($key, $r->headers['idempotency-key']);
        }
        $this->assertSame(json_encode(self::SEND, JSON_UNESCAPED_SLASHES), $t->requests[0]->body);
        $this->assertSame('application/json', $t->requests[0]->headers['content-type']);
        $client->emails->send(self::SEND);
        $this->assertNotSame($key, $t->requests[3]->headers['idempotency-key']);
    }

    public function testUsesTheCallersKeyAndStopsOnAnOrdinaryConflict(): void
    {
        [$client, $t] = $this->client(fn () => FakeTransport::json(409, '{"name":"invalid_idempotent_request","message":"another payload"}'));
        try {
            $client->emails->send(self::SEND, ['idempotency_key' => 'welcome/42']);
            $this->fail('no error');
        } catch (ConflictError) {
        }
        $this->assertCount(1, $t->requests);
        $this->assertSame('welcome/42', $t->requests[0]->headers['idempotency-key']);
    }

    public function testRetriesATimeoutAndReportsIt(): void
    {
        [$client, $t] = $this->client(function (int $n): Response {
            if ($n === 1) {
                throw new APITimeoutError('Request timed out after 50 ms');
            }
            return FakeTransport::json(200, '{"id":"e1"}');
        });
        $this->assertSame(['id' => 'e1'], $client->emails->get('e1'));
        $this->assertCount(2, $t->requests);
        [$client] = $this->client(fn () => throw new APIConnectionError('Network error: refused'), ['max_retries' => 0]);
        $this->expectException(APIConnectionError::class);
        $client->emails->get('e1');
    }

    public function testEncodesPathsQueriesAndHeaders(): void
    {
        [$client, $t] = $this->client(fn () => FakeTransport::json(200, '{"object":"list","data":[],"has_more":false}'));
        $client->inboxes->threads->search('support@example.com', ['limit' => 5, 'read' => false, 'query' => 'invoice paid', 'after' => null]);
        $this->assertSame('https://api.test/inboxes/support%40example.com/threads/search?limit=5&read=false&query=invoice%20paid', $t->requests[0]->url);
        $client->emails->get('a/b');
        $this->assertSame('https://api.test/emails/a%2Fb', $t->requests[1]->url);
        $client->emails->metrics->get(['metrics' => ['delivered', 'opened']]);
        $this->assertStringEndsWith('/emails/metrics?metrics=delivered%2Copened', $t->requests[2]->url);
        $client->emails->batch([self::SEND], ['x-batch-validation' => 'permissive']);
        $this->assertSame('permissive', $t->requests[3]->headers['x-batch-validation']);
        $this->assertStringStartsWith('[{', (string) $t->requests[3]->body);
        $this->assertStringNotContainsString('x-batch-validation', $t->requests[3]->url);
        try {
            $client->emails->get('');
            $this->fail('an empty path parameter went out');
        } catch (BillionSendError $e) {
            $this->assertStringContainsString('Missing path parameter', $e->getMessage());
        }
        $client->domains->create([]);
        $this->assertSame('{}', $t->requests[4]->body);
    }

    public function testAnOperationWithoutAuthSendsNoKey(): void
    {
        [$client, $t] = $this->client(fn () => FakeTransport::json(201, '{}'));
        $client->agent->signup(['human_email' => 'ana@example.com']);
        $this->assertArrayNotHasKey('authorization', $t->requests[0]->headers);
    }

    public function testHandsBackTheResponseHeaders(): void
    {
        [$client] = $this->client(fn () => FakeTransport::json(200, '{"id":"e1"}', ['x-request-id' => 'req_9']));
        $headers = null;
        $client->emails->get('e1', ['response_headers' => &$headers]);
        $this->assertSame('req_9', $headers['x-request-id']);
    }

    public function testNewIdempotencyKeyIsAVersion4Uuid(): void
    {
        $seen = [];
        for ($i = 0; $i < 100; $i++) {
            $k = Core::newIdempotencyKey();
            $this->assertMatchesRegularExpression(self::UUID_V4, $k);
            $this->assertArrayNotHasKey($k, $seen);
            $seen[$k] = true;
        }
    }

    public function testRefusesBadOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client('bs_x', ['max_retries' => -1]);
    }
}
