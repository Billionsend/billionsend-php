<?php

declare(strict_types=1);

namespace BillionSend;

use BillionSend\Exception\APIConnectionError;
use BillionSend\Exception\BillionSendError;

/**
 * A live inbox event stream. Iterate it with foreach: it yields
 * InboxStreamEvent and StreamGapEvent objects. Breaking out of the loop, or
 * calling close(), closes the connection. When the connection drops (the
 * server also closes every stream about every ten minutes) it reconnects with
 * Last-Event-ID and the events in between are replayed first, unless
 * 'reconnect' => false was given. After 'max_reconnects' failures in a row
 * (default 5) it throws an APIConnectionError; an error a retry cannot fix (a
 * revoked key, say) is thrown as its own class.
 *
 * @implements \IteratorAggregate<int, InboxStreamEvent|StreamGapEvent>
 */
final class EventStream implements \IteratorAggregate
{
    public const GAP_EVENT = 'stream.gap';

    /** @var resource|null */
    private $handle;
    private ?string $lastEventId;
    private float $delay;
    private bool $closed = false;
    private bool $started = false;
    /** @var array<string, true> */
    private array $endsOn = [];

    /**
     * @param callable(?string): Response $connect opens one connection, resuming after the id
     * @param array<string, mixed> $options
     * @param list<string> $endsOn event names after which the stream is over
     */
    public function __construct(
        private readonly mixed $connect,
        private readonly array $options,
        array $endsOn,
        private readonly Core $core,
    ) {
        foreach ($endsOn as $name) {
            $this->endsOn[$name] = true;
        }
        $this->lastEventId = isset($options['last_event_id']) ? (string) $options['last_event_id'] : null;
        $this->delay = (float) ($options['reconnect_delay'] ?? 1.0);
        $this->handle = ($this->connect)($this->lastEventId)->stream;
    }

    /** The id of the last event received: pass it as 'last_event_id' to resume later. */
    public function lastEventId(): ?string
    {
        return $this->lastEventId;
    }

    /** Closes the connection; a running loop ends without an error. */
    public function close(): void
    {
        $this->closed = true;
        $this->drop();
    }

    public function __destruct()
    {
        $this->drop();
    }

    private function drop(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }

    /**
     * @return \Generator<int, InboxStreamEvent|StreamGapEvent>
     */
    public function getIterator(): \Generator
    {
        if ($this->started) {
            throw new \LogicException('An EventStream can be iterated only once');
        }
        $this->started = true;
        $reconnect = ($this->options['reconnect'] ?? true) !== false;
        $maxReconnects = (int) ($this->options['max_reconnects'] ?? 5);
        $failures = 0;
        // The position a gap was last handed out for: the server repeats the
        // gap on every reconnect from that position until a newer event moves it.
        $gapAt = null;
        $gapGiven = false;
        try {
            while (!$this->closed) {
                if (is_resource($this->handle)) {
                    foreach ($this->events($this->handle) as $event) {
                        $failures = 0;
                        if ($event['event'] === self::GAP_EVENT) {
                            if ($gapGiven && $gapAt === $this->lastEventId) {
                                continue;
                            }
                            $gapAt = $this->lastEventId;
                            $gapGiven = true;
                            $data = json_decode($event['data'], true);
                            yield new StreamGapEvent(is_array($data) ? (string) ($data['reason'] ?? '') : '');
                            if ($this->closed) {
                                return;
                            }
                            continue;
                        }
                        $this->lastEventId = $event['id'];
                        $data = json_decode($event['data'], true);
                        yield new InboxStreamEvent((string) $event['id'], $event['event'], is_array($data) ? $data : [], $event['data']);
                        if ($this->closed || isset($this->endsOn[$event['event']])) {
                            return;
                        }
                    }
                    $this->drop();
                }
                if ($this->closed || !$reconnect) {
                    return;
                }
                $failures++;
                if ($failures > $maxReconnects) {
                    throw new APIConnectionError("The event stream disconnected; gave up after $maxReconnects reconnects");
                }
                ($this->core->sleep)($this->delay);
                try {
                    $this->handle = ($this->connect)($this->lastEventId)->stream;
                } catch (BillionSendError $error) {
                    if (!Core::retryable($error, false)) {
                        throw $error;
                    }
                }
            }
        } finally {
            $this->close();
        }
    }

    /**
     * Parses a text/event-stream body following the WHATWG rules: fields per
     * line, an event per blank line, comments and unknown fields skipped,
     * retry setting the reconnect delay, an unterminated event dropped.
     *
     * @param resource $handle
     * @return \Generator<int, array{id: string|null, event: string, data: string}>
     */
    private function events($handle): \Generator
    {
        $data = [];
        $event = '';
        $id = $this->lastEventId;
        $first = true;
        while (!$this->closed && is_resource($handle) && ($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($first) {
                $first = false;
                if (str_starts_with($line, "\u{FEFF}")) {
                    $line = substr($line, 3);
                }
            }
            if ($line === '') {
                if ($data !== []) {
                    yield ['id' => $id, 'event' => $event === '' ? 'message' : $event, 'data' => implode("\n", $data)];
                }
                $data = [];
                $event = '';
                continue;
            }
            if ($line[0] === ':') {
                continue;
            }
            $pos = strpos($line, ':');
            $field = $pos === false ? $line : substr($line, 0, $pos);
            $value = $pos === false ? '' : substr($line, $pos + 1);
            if (str_starts_with($value, ' ')) {
                $value = substr($value, 1);
            }
            switch ($field) {
                case 'data':
                    $data[] = $value;
                    break;
                case 'event':
                    $event = $value;
                    break;
                case 'id':
                    if (!str_contains($value, "\0")) {
                        $id = $value;
                    }
                    break;
                case 'retry':
                    if (ctype_digit($value)) {
                        $this->delay = ((int) $value) / 1000;
                    }
                    break;
            }
        }
    }
}
