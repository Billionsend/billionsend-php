<?php

declare(strict_types=1);

namespace BillionSend\Exception;

/**
 * The request timed out before the answer arrived.
 */
class APITimeoutError extends APIConnectionError
{
}
