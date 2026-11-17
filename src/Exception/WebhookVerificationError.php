<?php

declare(strict_types=1);

namespace BillionSend\Exception;

/**
 * A webhook whose signature, timestamp or body did not verify (Webhook::verify).
 */
class WebhookVerificationError extends BillionSendError
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'webhook_verification_error');
    }
}
