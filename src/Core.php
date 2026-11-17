<?php

declare(strict_types=1);

namespace BillionSend;

use BillionSend\Exception\APIConnectionError;
use BillionSend\Exception\APIError;
use BillionSend\Exception\AuthenticationError;
use BillionSend\Exception\BillionSendError;
use BillionSend\Exception\ConflictError;
use BillionSend\Exception\NotFoundError;
use BillionSend\Exception\PermissionDeniedError;
use BillionSend\Exception\RateLimitError;
use BillionSend\Exception\ValidationError;

/**
 * The HTTP layer shared by every service: authentication, idempotency keys,
 * retries and error mapping.
 *
 * @phpstan-type RequestOptions array{
 *     idempotency_key?: string,
 *     headers?: array<string, string>,
 *     timeout?: float,
 *     max_retries?: int,
 *     response_headers?: array<string, string>,
 * }
 * @phpstan-type EventStreamOptions array{
 *     headers?: array<string, string>,
 *     timeout?: float,
 *     max_retries?: int,
 *     last_event_id?: string,
 *     reconnect?: bool,
 *     max_reconnects?: int,
 *     reconnect_delay?: float,
 * }
 * @internal
 */
final class Core
{
    /** A retry-after longer than this (seconds) is not waited for: the error is thrown instead. */
    public const MAX_RETRY_AFTER = 60.0;
    private const BASE_DELAY = 0.5;
    private const MAX_BACKOFF = 8.0;
    /** Methods safe to repeat without an Idempotency-Key. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE'];

    /** @var callable(float): void */
    public $sleep;
    /** @var callable(): float */
    public $random;

    /**
     * @param array<string, string> $headers lower-case names
     */
    public function __construct(
        public readonly ?string $apiKey,
        public readonly string $baseUrl,
        public readonly Transport $transport,
        public readonly float $timeout,
        public readonly int $maxRetries,
        private readonly array $headers,
    ) {
        $this->sleep = static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
        $this->random = static fn (): float => mt_rand() / mt_getrandmax();
    }

    /**
     * Calls one operation and returns its decoded JSON answer.
     *
     * @param array<string, mixed> $params
     * @param array<mixed>|null $body
     * @param RequestOptions $options
     * @return array<string, mixed>
     */
    public function request(Operation $op, array $params, ?array $body, array $options): array
    {
        [$request, $keyed, $retryable, $maxRetries] = $this->prepare($op, $params, $body, $options, false);
        $response = $this->send($request, $keyed, $retryable, $maxRetries, false);
        if (array_key_exists('response_headers', $options)) {
            // A reference the caller put in: ['response_headers' => &$headers].
            $options['response_headers'] = $response->headers;
        }
        if (trim($response->body) === '') {
            return [];
        }
        try {
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BillionSendError(
                'The response body is not valid JSON',
                'application_error',
                $response->status,
                $response->header('x-request-id'),
            );
        }
        return is_array($data) ? $data : ['value' => $data];
    }

    /**
     * Opens an inbox event stream.
     *
     * @param array<string, mixed> $params
     * @param EventStreamOptions $options
     * @param list<string> $endsOn event names after which the stream is over
     */
    public function stream(Operation $op, array $params, array $options, array $endsOn): EventStream
    {
        [$request, , , $maxRetries] = $this->prepare($op, $params, null, $options, true);
        $connect = function (?string $lastEventId) use ($request, $maxRetries): Response {
            $headers = $request->headers;
            if ($lastEventId !== null && $lastEventId !== '') {
                $headers['last-event-id'] = $lastEventId;
            }
            $attempt = new Request($request->method, $request->url, $headers, null, $request->timeout);
            return $this->send($attempt, false, true, $maxRetries, true);
        };
        return new EventStream($connect, $options, $endsOn, $this);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<mixed>|null $body
     * @param array<string, mixed> $options
     * @return array{0: Request, 1: bool, 2: bool, 3: int}
     */
    private function prepare(Operation $op, array $params, ?array $body, array $options, bool $stream): array
    {
        if (!$op->noAuth && ($this->apiKey === null || $this->apiKey === '')) {
            throw new AuthenticationError(
                'No API key: pass one to new Client() or set BILLIONSEND_API_KEY',
                'missing_api_key',
            );
        }
        $path = $op->path;
        foreach ($op->pathParams as $name => $value) {
            if ($value === '') {
                throw new BillionSendError("Missing path parameter \"$name\"");
            }
            $path = str_replace('{' . $name . '}', rawurlencode($value), $path);
        }
        $query = [];
        $paramHeaders = [];
        foreach ($params as $name => $value) {
            if ($value === null) {
                continue;
            }
            $text = self::paramText($value);
            if (in_array($name, $op->headerParams, true)) {
                $paramHeaders[strtolower((string) $name)] = $text;
            } else {
                $query[] = rawurlencode((string) $name) . '=' . rawurlencode($text);
            }
        }
        $url = $this->baseUrl . $path . ($query ? '?' . implode('&', $query) : '');
        $headers = [
            'accept' => $stream ? 'text/event-stream' : 'application/json',
            'user-agent' => 'billionsend-php/' . Version::VERSION,
        ];
        foreach ([$this->headers, self::lower($options['headers'] ?? []), $paramHeaders] as $extra) {
            foreach ($extra as $k => $v) {
                $headers[$k] = $v;
            }
        }
        $encoded = null;
        if ($body !== null) {
            // An empty PHP array is an empty JSON object unless the body is a list.
            $value = $body === [] && !$op->listBody ? new \stdClass() : $body;
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers['content-type'] = 'application/json';
        }
        if (isset($options['idempotency_key'])) {
            $headers['idempotency-key'] = (string) $options['idempotency_key'];
        } elseif ($op->idempotent && !isset($headers['idempotency-key'])) {
            $headers['idempotency-key'] = self::newIdempotencyKey();
        }
        if ($op->noAuth) {
            unset($headers['authorization']);
        } else {
            $headers['authorization'] = 'Bearer ' . $this->apiKey;
        }
        $keyed = isset($headers['idempotency-key']);
        $retryable = in_array($op->method, self::SAFE_METHODS, true) || $keyed;
        $maxRetries = (int) ($options['max_retries'] ?? $this->maxRetries);
        $timeout = (float) ($options['timeout'] ?? $this->timeout);
        return [new Request($op->method, $url, $headers, $encoded, $timeout), $keyed, $retryable, $maxRetries];
    }

    /**
     * Runs the attempts of one request: the answer of the first that succeeds,
     * or the error of the last.
     */
    public function send(Request $request, bool $keyed, bool $retryable, int $maxRetries, bool $stream): Response
    {
        for ($attempt = 0; ; $attempt++) {
            $retryAfter = null;
            try {
                $response = $this->transport->send($request, $stream);
                if ($response->status >= 200 && $response->status < 300) {
                    if ($stream && !str_contains((string) $response->header('content-type'), 'text/event-stream')) {
                        if (is_resource($response->stream)) {
                            fclose($response->stream);
                        }
                        throw new BillionSendError(
                            'Expected an event stream, got ' . ($response->header('content-type') ?? 'no content type'),
                            'application_error',
                            $response->status,
                            $response->header('x-request-id'),
                        );
                    }
                    return $response;
                }
                $retryAfter = $response->header('retry-after');
                throw self::errorFromResponse($response);
            } catch (BillionSendError $error) {
                if (!$retryable || $attempt >= $maxRetries || !self::retryable($error, $keyed)) {
                    throw $error;
                }
                $delay = $this->retryDelay($attempt, $retryAfter);
                if ($delay === null) {
                    throw $error;
                }
                ($this->sleep)($delay);
            }
        }
    }

    /**
     * How long to wait before retry number $attempt + 1, in seconds: the
     * server's retry-after (seconds or an HTTP date) when it sent one,
     * otherwise an exponential backoff with jitter (0.5 s doubling, at most
     * 8 s, less up to a quarter). Null means "do not retry": the answer for a
     * retry-after longer than MAX_RETRY_AFTER.
     */
    public function retryDelay(int $attempt, ?string $retryAfter, ?float $now = null): ?float
    {
        $hinted = self::parseRetryAfter($retryAfter, $now);
        if ($hinted !== null) {
            return $hinted > self::MAX_RETRY_AFTER ? null : $hinted;
        }
        $backoff = min(self::BASE_DELAY * (2 ** $attempt), self::MAX_BACKOFF);
        return $backoff * (1 - ($this->random)() * 0.25);
    }

    /** Seconds from a retry-after value (seconds or an HTTP date), or null. */
    public static function parseRetryAfter(?string $value, ?float $now = null): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }
        $at = strtotime($value);
        if ($at === false) {
            return null;
        }
        return max(0.0, $at - ($now ?? microtime(true)));
    }

    /**
     * Whether a failed attempt may be retried: a 429, a 5xx, a timeout or a
     * network failure; and, for a request that carries an Idempotency-Key,
     * the 409 concurrent_idempotent_requests the API answers while the first
     * attempt with that key is still being processed.
     */
    public static function retryable(BillionSendError $error, bool $keyed): bool
    {
        if ($error instanceof APIConnectionError) {
            return true;
        }
        $status = $error->getStatusCode();
        if ($status === null) {
            return false;
        }
        if ($status === 429 || $status >= 500) {
            return true;
        }
        return $keyed && $status === 409 && $error->getErrorName() === 'concurrent_idempotent_requests';
    }

    /** Maps a non-2xx answer to its error class, keeping the API's own ErrorResponse. */
    public static function errorFromResponse(Response $response): BillionSendError
    {
        $status = $response->status;
        $name = 'application_error';
        $message = '';
        $data = json_decode($response->body, true);
        if (is_array($data) && isset($data['message']) && is_string($data['message']) && $data['message'] !== '') {
            $message = $data['message'];
            if (isset($data['name']) && is_string($data['name']) && $data['name'] !== '') {
                $name = $data['name'];
            }
        } else {
            $message = trim(substr($response->body, 0, 500));
            if ($message === '') {
                $message = "HTTP $status";
            }
        }
        $requestId = $response->header('x-request-id');
        return match (true) {
            $status === 400, $status === 422 => new ValidationError($message, $name, $status, $requestId),
            $status === 401 => new AuthenticationError($message, $name, $status, $requestId),
            $status === 403 => new PermissionDeniedError($message, $name, $status, $requestId),
            $status === 404 => new NotFoundError($message, $name, $status, $requestId),
            $status === 409 => new ConflictError($message, $name, $status, $requestId),
            $status === 429 => new RateLimitError($message, $name, $status, $requestId, self::parseRetryAfter($response->header('retry-after'))),
            $status >= 500 => new APIError($message, $name, $status, $requestId, self::parseRetryAfter($response->header('retry-after'))),
            default => new BillionSendError($message, $name, $status, $requestId),
        };
    }

    /** A new idempotency key: a random UUID (version 4). */
    public static function newIdempotencyKey(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20));
    }

    /** A query or header value: lists comma-separated in one key, booleans as true / false. */
    private static function paramText(mixed $value): string
    {
        if (is_array($value)) {
            return implode(',', array_map(fn ($v) => self::paramText($v), $value));
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function lower(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[strtolower((string) $k)] = (string) $v;
        }
        return $out;
    }
}
