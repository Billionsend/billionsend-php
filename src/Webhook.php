<?php

declare(strict_types=1);

namespace BillionSend;

use BillionSend\Exception\WebhookVerificationError;

/**
 * Webhook signature verification.
 */
final class Webhook
{
    /** How far webhook-timestamp may be from now by default, in seconds. */
    public const TOLERANCE = 300;

    private const SECRET_PREFIX = 'whsec_';
    private const SECRET_MIN_BYTES = 24;
    private const SECRET_MAX_BYTES = 64;

    /**
     * Verifies a webhook delivery and returns its parsed body.
     *
     * The signature is HMAC-SHA256 over "<webhook-id>.<webhook-timestamp>.<body>"
     * with the endpoint's whsec_ secret, sent as "v1,<base64>" in
     * webhook-signature; during a secret rotation the header carries one
     * signature per secret, space-separated, and any of them matching is
     * enough. The timestamp (Unix seconds) must be within $tolerance of now,
     * so a captured delivery cannot be replayed later.
     *
     * Pass the body exactly as it arrived (file_get_contents('php://input')),
     * before any JSON parsing: re-serialised JSON does not verify.
     *
     * @param array<string, string|list<string>> $headers the request headers, any case
     * @return array<string, mixed> the event: type, created_at, data
     * @throws WebhookVerificationError when a header is missing, the timestamp is
     *   out of tolerance, no signature matches, the secret is malformed, or the
     *   body is not JSON
     */
    public static function verify(string $payload, array $headers, string $secret, int $tolerance = self::TOLERANCE, ?int $now = null): array
    {
        $id = self::header($headers, 'webhook-id');
        $timestamp = self::header($headers, 'webhook-timestamp');
        $signature = self::header($headers, 'webhook-signature');
        if ($id === null || $timestamp === null || $signature === null || $id === '' || $timestamp === '' || $signature === '') {
            throw new WebhookVerificationError('Missing the webhook-id, webhook-timestamp or webhook-signature header');
        }
        if (!ctype_digit($timestamp)) {
            throw new WebhookVerificationError('Invalid webhook-timestamp header');
        }
        $now ??= time();
        $seconds = (int) $timestamp;
        if ($now - $seconds > $tolerance) {
            throw new WebhookVerificationError('The webhook timestamp is too old');
        }
        if ($seconds - $now > $tolerance) {
            throw new WebhookVerificationError('The webhook timestamp is too new');
        }
        $expected = hash_hmac('sha256', "$id.$timestamp.$payload", self::key($secret), true);
        $matched = false;
        foreach (explode(' ', $signature) as $candidate) {
            $parts = explode(',', $candidate, 2);
            if (count($parts) !== 2 || $parts[0] !== 'v1') {
                continue;
            }
            $given = base64_decode($parts[1], true);
            if ($given !== false && hash_equals($expected, $given)) {
                $matched = true;
            }
        }
        if (!$matched) {
            throw new WebhookVerificationError('No webhook signature matches');
        }
        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new WebhookVerificationError('The webhook body is not JSON');
        }
        if (!is_array($event)) {
            throw new WebhookVerificationError('The webhook body is not JSON');
        }
        return $event;
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== $name) {
                continue;
            }
            return is_array($value) ? (isset($value[0]) ? (string) $value[0] : null) : (string) $value;
        }
        return null;
    }

    /** The key of a whsec_ secret: canonical base64 of 24 to 64 bytes, as the dashboard and the API hand it out. */
    private static function key(string $secret): string
    {
        if (str_starts_with($secret, self::SECRET_PREFIX)) {
            $encoded = substr($secret, strlen(self::SECRET_PREFIX));
            $key = base64_decode($encoded, true);
            if ($key !== false && strlen($key) >= self::SECRET_MIN_BYTES && strlen($key) <= self::SECRET_MAX_BYTES && base64_encode($key) === $encoded) {
                return $key;
            }
        }
        throw new WebhookVerificationError('The webhook secret is not a whsec_ secret');
    }
}
