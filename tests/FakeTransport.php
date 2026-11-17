<?php

declare(strict_types=1);

namespace BillionSend\Tests;

use BillionSend\Request;
use BillionSend\Response;
use BillionSend\Transport;

/**
 * A transport that answers from a script and keeps every request.
 */
final class FakeTransport implements Transport
{
    /** @var list<Request> */
    public array $requests = [];
    /** @var list<bool> */
    public array $streams = [];

    /**
     * @param callable(int, Request, bool): Response $answer
     */
    public function __construct(private $answer)
    {
    }

    public function send(Request $request, bool $stream = false): Response
    {
        $this->requests[] = $request;
        $this->streams[] = $stream;
        return ($this->answer)(count($this->requests), $request, $stream);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function json(int $status, string $body, array $headers = []): Response
    {
        return new Response($status, $headers + ['content-type' => 'application/json'], $body);
    }

    public static function stream(string $body, int $status = 200): Response
    {
        $handle = fopen('php://memory', 'w+b');
        fwrite($handle, $body);
        rewind($handle);
        return new Response($status, ['content-type' => 'text/event-stream'], '', $handle);
    }
}
