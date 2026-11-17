<?php

declare(strict_types=1);

namespace BillionSend\Exception;

/**
 * 429: a rate limit, a sending quota or a stream cap. getRetryAfter() is the
 * server's hint in seconds, null without one.
 */
class RateLimitError extends BillionSendError
{
    public function __construct(
        string $message,
        string $errorName = 'application_error',
        ?int $statusCode = 429,
        ?string $requestId = null,
        private readonly ?float $retryAfter = null,
    ) {
        parent::__construct($message, $errorName, $statusCode, $requestId);
    }

    public function getRetryAfter(): ?float
    {
        return $this->retryAfter;
    }
}
