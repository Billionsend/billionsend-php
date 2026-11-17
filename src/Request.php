<?php

declare(strict_types=1);

namespace BillionSend;

/**
 * One HTTP request, as a Transport sends it.
 */
final class Request
{
    /**
     * @param array<string, string> $headers lower-case names
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly float $timeout,
    ) {
    }
}
