<?php

declare(strict_types=1);

namespace BillionSend;

/**
 * One HTTP answer. A non-stream answer carries its whole body; a stream's
 * successful answer carries an open resource to read lines from instead.
 */
final class Response
{
    /**
     * @param array<string, string> $headers lower-case names
     * @param resource|null $stream
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body = '',
        public readonly mixed $stream = null,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
