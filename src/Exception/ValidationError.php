<?php

declare(strict_types=1);

namespace BillionSend\Exception;

/**
 * 400 or 422: the request was refused as invalid.
 */
class ValidationError extends BillionSendError
{
}
