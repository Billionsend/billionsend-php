<?php

declare(strict_types=1);

namespace BillionSend\Tests;

use BillionSend\Client;
use BillionSend\CurlTransport;
use BillionSend\Exception\APITimeoutError;
use BillionSend\Exception\NotFoundError;
use BillionSend\InboxStreamEvent;
use BillionSend\Operation;
use BillionSend\Request;

/**
 * The default transport against PHP's built-in web server.
 */
final class CurlTransportTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        $port = 20000 + random_int(0, 9999);
        self::$base = "http://127.0.0.1:$port";
        self::$server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/Fixtures/router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(50_000);
        }
        self::fail('the built-in server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    public function testSendsTheRequestAndReadsTheAnswer(): void
    {
        $answer = (new CurlTransport())->send(new Request('POST', self::$base . '/echo?a=1', ['authorization' => 'Bearer bs_x', 'idempotency-key' => 'k1', 'content-type' => 'application/json'], '{"x":1}', 5.0));
        $this->assertSame(200, $answer->status);
        $this->assertSame('req_echo', $answer->header('x-request-id'));
        $this->assertSame(['method' => 'POST', 'query' => 'a=1', 'authorization' => 'Bearer bs_x', 'idempotency_key' => 'k1', 'body' => '{"x":1}'], json_decode($answer->body, true));
    }

    public function testMapsAnErrorAnswerAndTimesOut(): void
    {
        $client = new Client('bs_x', ['base_url' => self::$base, 'max_retries' => 0, 'timeout' => 0.3]);
        try {
            $client->core()->request(new Operation('x', 'GET', '/missing'), [], null, []);
            $this->fail('no error');
        } catch (NotFoundError $e) {
            $this->assertSame(['statusCode' => 404, 'name' => 'not_found', 'message' => 'No such email', 'requestId' => 'req_404'], $e->toArray());
        }
        $this->expectException(APITimeoutError::class);
        $client->core()->request(new Operation('x', 'GET', '/slow'), [], null, []);
    }

    public function testReadsAnEventStreamAndReconnects(): void
    {
        $client = new Client('bs_x', ['base_url' => self::$base, 'max_retries' => 0]);
        $client->core()->sleep = static function (float $s): void {
        };
        $stream = $client->core()->stream(new Operation('x', 'GET', '/events'), [], ['max_reconnects' => 1], ['inbox.deleted']);
        $got = [];
        foreach ($stream as $item) {
            $this->assertInstanceOf(InboxStreamEvent::class, $item);
            $got[] = $item->id . ':' . $item->event;
        }
        $this->assertSame(['1:inbox.updated', 'none:inbox.deleted'], $got);
    }
}
