<?php

declare(strict_types=1);

namespace BillionSend;

/**
 * The BillionSend API client. Every API operation is a method of a service,
 * named after its operationId: emails.send is $client->emails->send(),
 * emails.receiving.attachments.list is
 * $client->emails->receiving->attachments->list(). Answers come back as the
 * decoded JSON (associative arrays), exactly as the API document describes
 * them.
 *
 * ```php
 * $client = new BillionSend\Client(); // BILLIONSEND_API_KEY
 * $sent = $client->emails->send([
 *     'from' => 'Acme <hello@acme.dev>',
 *     'to' => ['ana@example.com'],
 *     'subject' => 'Welcome',
 *     'html' => '<p>Glad you are here.</p>',
 * ]);
 * ```
 */
final class Client
{
    use ClientServices;

    public const DEFAULT_BASE_URL = 'https://api.billionsend.com';
    /** Seconds each attempt may take; event streams are timed only until they connect. */
    public const DEFAULT_TIMEOUT = 60.0;
    public const DEFAULT_MAX_RETRIES = 2;

    private readonly Core $core;

    /**
     * @param string|null $apiKey the API key (bs_...); defaults to BILLIONSEND_API_KEY
     * @param array{
     *     base_url?: string,
     *     timeout?: float,
     *     max_retries?: int,
     *     headers?: array<string, string>,
     *     transport?: Transport,
     * } $options base_url defaults to BILLIONSEND_BASE_URL, then
     *   https://api.billionsend.com; timeout (seconds) bounds each attempt
     *   (default 60); max_retries is the retries for 429, 5xx, timeouts and
     *   network failures (default 2), only of requests safe to repeat: GET,
     *   PUT and DELETE, and the sending operations, which always carry an
     *   Idempotency-Key; headers go with every request; transport replaces
     *   the cURL one.
     */
    public function __construct(?string $apiKey = null, array $options = [])
    {
        $apiKey ??= self::env('BILLIONSEND_API_KEY');
        $baseUrl = rtrim($options['base_url'] ?? self::env('BILLIONSEND_BASE_URL') ?? self::DEFAULT_BASE_URL, '/');
        $timeout = (float) ($options['timeout'] ?? self::DEFAULT_TIMEOUT);
        $maxRetries = (int) ($options['max_retries'] ?? self::DEFAULT_MAX_RETRIES);
        if ($timeout <= 0) {
            throw new \InvalidArgumentException('timeout must be a positive number of seconds');
        }
        if ($maxRetries < 0) {
            throw new \InvalidArgumentException('max_retries must be zero or more');
        }
        $headers = [];
        foreach ($options['headers'] ?? [] as $k => $v) {
            $headers[strtolower((string) $k)] = (string) $v;
        }
        $this->core = new Core($apiKey, $baseUrl, $options['transport'] ?? new CurlTransport(), $timeout, $maxRetries, $headers);
        $this->initServices($this->core);
    }

    /** The HTTP layer, for tests that replace its clock. @internal */
    public function core(): Core
    {
        return $this->core;
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);
        return $value === false || $value === '' ? null : $value;
    }
}
