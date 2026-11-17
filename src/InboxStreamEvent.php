<?php

declare(strict_types=1);

namespace BillionSend;

/**
 * One inbox event. $id is its stream position: keep the last one to resume
 * with 'last_event_id'. $data is the event's JSON body: type, created_at,
 * inbox_id and data (the matching webhook event's metadata: ids such as
 * thread_id, email_id and draft_id, never a body, code or link).
 */
final class InboxStreamEvent
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $id,
        public readonly string $event,
        public readonly array $data,
        public readonly string $raw,
    ) {
    }

    /** The inbox the event is about. */
    public function inboxId(): ?string
    {
        $id = $this->data['inbox_id'] ?? null;
        return is_string($id) ? $id : null;
    }

    /**
     * The webhook payload's data (ids such as thread_id, email_id, draft_id).
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->data['data'] ?? [];
        return is_array($data) ? $data : [];
    }
}
