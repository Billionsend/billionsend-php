<?php

declare(strict_types=1);

namespace BillionSend;

/**
 * The stream resumed from a position older than the server keeps (24 hours):
 * events may be missing, so read the REST lists again. It does not move the
 * stream position, and one stream hands it out once per position.
 */
final class StreamGapEvent
{
    public const EVENT = 'stream.gap';

    public function __construct(public readonly string $reason)
    {
    }
}
