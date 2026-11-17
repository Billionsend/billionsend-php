<?php

declare(strict_types=1);

namespace BillionSend\Tests;

use BillionSend\Client;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    public const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** @var list<float> */
    protected array $waits = [];

    /**
     * A client on a fake transport, with no real waiting between retries.
     *
     * @param callable(int, \BillionSend\Request, bool): \BillionSend\Response $answer
     * @param array<string, mixed> $options
     * @return array{0: Client, 1: FakeTransport}
     */
    protected function client(callable $answer, array $options = []): array
    {
        $transport = new FakeTransport($answer);
        $client = new Client('bs_test', $options + ['base_url' => 'https://api.test', 'transport' => $transport]);
        $this->waits = [];
        $client->core()->sleep = function (float $s): void {
            $this->waits[] = $s;
        };
        $client->core()->random = static fn (): float => 0.0;
        return [$client, $transport];
    }
}
