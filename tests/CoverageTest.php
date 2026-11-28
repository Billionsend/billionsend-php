<?php

declare(strict_types=1);

namespace BillionSend\Tests;

use BillionSend\Client;
use BillionSend\Request;
use BillionSend\Response;
use BillionSend\Tests\Generated\CoverageCalls;

/**
 * Every operation of the snapshot is reached by exactly one generated call,
 * every call reaches the operation it is named after, and nothing the
 * snapshot does not have is ever requested.
 */
final class CoverageTest extends TestCase
{
    /**
     * @return list<array{id: string, method: string, template: string, pattern: string, status: int, stream: bool, idempotent: bool, noAuth: bool}>
     */
    private static function snapshot(): array
    {
        $doc = json_decode((string) file_get_contents(__DIR__ . '/../openapi/api.openapi.json'), true);
        $ops = [];
        foreach ($doc['paths'] as $path => $item) {
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $m) {
                if (!isset($item[$m])) {
                    continue;
                }
                $o = $item[$m];
                $codes = array_values(array_filter(array_keys($o['responses']), fn ($c) => str_starts_with((string) $c, '2')));
                sort($codes);
                $idempotent = false;
                foreach ($o['parameters'] ?? [] as $p) {
                    if ($p['in'] === 'header' && strcasecmp($p['name'], 'Idempotency-Key') === 0) {
                        $idempotent = true;
                    }
                }
                $ops[] = [
                    'id' => $o['operationId'],
                    'method' => strtoupper($m),
                    'template' => $path,
                    'pattern' => '#^' . preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/]+', preg_quote($path, '#')) . '$#',
                    'status' => (int) $codes[0],
                    'stream' => isset($o['responses'][$codes[0]]['content']['text/event-stream']),
                    'idempotent' => $idempotent,
                    'noAuth' => isset($o['security']) && $o['security'] === [],
                ];
            }
        }
        // A literal path wins over a templated one (/emails/metrics before /emails/{id}).
        usort($ops, fn ($a, $b) => substr_count($a['template'], '{') <=> substr_count($b['template'], '{'));
        return $ops;
    }

    public function testEveryOperationIsCoveredAndNothingElse(): void
    {
        $ops = self::snapshot();
        $hits = [];
        $unknown = [];
        $answer = function (int $n, Request $r, bool $stream) use ($ops, &$hits, &$unknown): Response {
            $path = (string) parse_url($r->url, PHP_URL_PATH);
            foreach ($ops as $op) {
                if ($op['method'] !== $r->method || !preg_match($op['pattern'], $path)) {
                    continue;
                }
                $problem = '';
                $auth = $r->headers['authorization'] ?? null;
                $key = $r->headers['idempotency-key'] ?? null;
                if ($op['noAuth'] && $auth !== null) {
                    $problem = 'sent a key to an operation that takes none';
                } elseif (!$op['noAuth'] && $auth !== 'Bearer bs_test') {
                    $problem = 'no bearer key';
                } elseif ($op['idempotent'] && !preg_match(self::UUID_V4, (string) $key)) {
                    $problem = 'no generated Idempotency-Key';
                } elseif (!$op['idempotent'] && $key !== null) {
                    $problem = 'an Idempotency-Key on an operation that takes none';
                } elseif ($stream !== $op['stream']) {
                    $problem = 'opened as the wrong kind of answer';
                }
                $hits[$op['id']][] = $problem;
                return $op['stream'] ? FakeTransport::stream('', $op['status']) : FakeTransport::json($op['status'], '{}');
            }
            $unknown[] = "{$r->method} $path";
            return FakeTransport::json(599, '{}');
        };
        [$client] = $this->client($answer, ['max_retries' => 0]);

        $calls = CoverageCalls::all();
        $snapshot = array_column($ops, 'id');
        sort($snapshot);
        $covered = array_keys($calls);
        sort($covered);
        $this->assertSame($snapshot, $covered, 'one call per operation of the snapshot');
        $this->assertCount(193, $snapshot);
        foreach ($calls as $id => $call) {
            $before = count($hits[$id] ?? []);
            $call($client);
            $got = array_slice($hits[$id] ?? [], $before);
            $this->assertCount(1, $got, "$id reached its operation once");
            $this->assertSame([''], $got, "$id: " . implode(', ', $got));
        }
        $this->assertSame([], $unknown, 'requests the snapshot does not have');
        $this->assertSame(count($calls), array_sum(array_map('count', $hits)));
    }
}
