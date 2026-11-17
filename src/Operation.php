<?php

declare(strict_types=1);

namespace BillionSend;

/**
 * One API operation, as the generated service methods describe it.
 *
 * @internal
 */
final class Operation
{
    /**
     * @param array<string, string> $pathParams path parameter name => value
     * @param list<string> $headerParams the names of the parameters sent as headers
     * @param bool $listBody the body is a JSON array (an empty PHP array is sent as [], not {})
     */
    public function __construct(
        public readonly string $id,
        public readonly string $method,
        public readonly string $path,
        public readonly array $pathParams = [],
        public readonly bool $idempotent = false,
        public readonly bool $noAuth = false,
        public readonly array $headerParams = [],
        public readonly bool $listBody = false,
    ) {
    }
}
