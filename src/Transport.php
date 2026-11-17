<?php

declare(strict_types=1);

namespace BillionSend;

use BillionSend\Exception\APIConnectionError;

/**
 * Sends requests. The default is CurlTransport; pass another to Client to go
 * through your own HTTP stack (or a fake one in tests).
 */
interface Transport
{
    /**
     * Sends one request and returns the answer, whatever its status.
     *
     * @param bool $stream open a text/event-stream: a 2xx answer comes back with
     *   its body unread, as a resource in Response::$stream
     * @throws APIConnectionError when there is no answer (APITimeoutError when
     *   the attempt timed out)
     */
    public function send(Request $request, bool $stream = false): Response;
}
