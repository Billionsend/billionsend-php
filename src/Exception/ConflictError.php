<?php

declare(strict_types=1);

namespace BillionSend\Exception;

/**
 * 409: the request conflicts with the current state, or with an earlier use of its idempotency key.
 */
class ConflictError extends BillionSendError
{
}
