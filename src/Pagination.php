<?php

declare(strict_types=1);

namespace BillionSend;

use BillionSend\Exception\BillionSendError;

/**
 * @internal
 */
final class Pagination
{
    /**
     * Walks a cursor-paginated list one item at a time, fetching pages on
     * demand. Starting with $params['before'] set walks backward (using the
     * first id of each page); otherwise it walks forward from
     * $params['after'] (using the last id). A failed page throws its error,
     * and so does an answer that is not a list page (no data array, or an item
     * to continue from without an id), as a BillionSendError named
     * application_error: ending quietly would pass off a truncated walk as the
     * whole list.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $fetch
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public static function walk(callable $fetch, array $params): \Generator
    {
        $backward = isset($params['before']) && $params['before'] !== '';
        if ($backward) {
            unset($params['after']);
        }
        while (true) {
            $page = $fetch($params);
            $items = $page['data'] ?? null;
            if (!is_array($items) || !array_is_list($items)) {
                throw new BillionSendError('The list page has no data array');
            }
            foreach ($items as $item) {
                yield $item;
            }
            if (empty($page['has_more']) || $items === []) {
                return;
            }
            $edge = $backward ? $items[0] : $items[count($items) - 1];
            $id = is_array($edge) ? ($edge['id'] ?? null) : null;
            if (!is_string($id) || $id === '') {
                throw new BillionSendError('The item of the list page to continue from has no id');
            }
            if ($backward) {
                $params['before'] = $id;
            } else {
                $params['after'] = $id;
            }
        }
    }
}
