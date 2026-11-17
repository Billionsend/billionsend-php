<?php

declare(strict_types=1);

namespace BillionSend\Exception;

/**
 * Every error the SDK throws. Tell the kinds apart by class:
 * ValidationError, AuthenticationError, PermissionDeniedError, NotFoundError,
 * ConflictError, RateLimitError, APIError, APIConnectionError and
 * APITimeoutError.
 *
 * getErrorName() is the API's error name ("validation_error",
 * "invalid_api_key", ...); failures that never got an API answer use
 * "application_error", with getStatusCode() null when there was no HTTP
 * response at all. getRequestId() is the answer's x-request-id (the request
 * on the dashboard's Logs page), null without one.
 */
class BillionSendError extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorName = 'application_error',
        private readonly ?int $statusCode = null,
        private readonly ?string $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getErrorName(): string
    {
        return $this->errorName;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * @return array{statusCode: int|null, name: string, message: string, requestId: string|null}
     */
    public function toArray(): array
    {
        return [
            'statusCode' => $this->statusCode,
            'name' => $this->errorName,
            'message' => $this->getMessage(),
            'requestId' => $this->requestId,
        ];
    }
}
