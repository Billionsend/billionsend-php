<?php

declare(strict_types=1);

namespace BillionSend\Exception;

/**
 * 403: the key, its scope, the role or the plan does not allow this.
 */
class PermissionDeniedError extends BillionSendError
{
}
