<?php

declare(strict_types=1);

namespace BillionSend\Exception;

/**
 * 5xx: the API failed to answer the request. getRetryAfter() is the server's
 * hint in seconds, null without one.
 */
class APIError extends BillionSendError
{
    public function __construct(
        string $message,
        string $errorName = 'application_error',
        ?int $statusCode = 500,
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
