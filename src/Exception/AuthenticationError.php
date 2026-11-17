<?php

declare(strict_types=1);

namespace BillionSend\Exception;

/**
 * 401, or no API key configured (missing_api_key, status null): the key is missing, malformed or revoked.
 */
class AuthenticationError extends BillionSendError
{
}
