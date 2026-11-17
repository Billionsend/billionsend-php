<?php

declare(strict_types=1);

namespace BillionSend\Exception;

/**
 * The request never got an answer: DNS, connection, TLS or protocol failure.
 * getStatusCode() is null.
 */
class APIConnectionError extends BillionSendError
{
    public function __construct(string $message = 'Connection error', ?\Throwable $previous = null)
    {
        parent::__construct($message, 'application_error', null, null, $previous);
    }
}
