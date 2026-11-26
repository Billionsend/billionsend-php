<?php

declare(strict_types=1);

namespace BillionSend;

use BillionSend\Exception\APIConnectionError;
use BillionSend\Exception\APITimeoutError;

/**
 * The default transport: cURL for requests, a PHP HTTP stream for event
 * streams (read line by line as events arrive).
 */
final class CurlTransport implements Transport
{
    /** How long an open event stream may stay silent before it counts as dropped (the server sends keep-alive comments well within it). */
    public const STREAM_IDLE_TIMEOUT = 60.0;

    public function send(Request $request, bool $stream = false): Response
    {
        return $stream ? $this->openStream($request) : $this->request($request);
    }

    private function request(Request $request): Response
    {
        $ch = curl_init($request->url);
        if ($ch === false) {
            throw new APIConnectionError('Network error: could not start cURL');
        }
        $headers = [];
        $lines = [];
        foreach ($request->headers as $k => $v) {
            $lines[] = "$k: $v";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) round($request->timeout * 1000),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                } elseif (str_starts_with($line, 'HTTP/')) {
                    $headers = [];
                }
                return strlen($line);
            },
        ]);
        if ($request->body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $request->body);
        }
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            throw new APITimeoutError(sprintf('Request timed out after %.0f ms', $request->timeout * 1000));
        }
        if ($body === false || $errno !== 0) {
            throw new APIConnectionError("Network error: $error");
        }
        return new Response($status, $headers, (string) $body);
    }

    private function openStream(Request $request): Response
    {
        $lines = [];
        foreach ($request->headers as $k => $v) {
            $lines[] = "$k: $v";
        }
        $context = stream_context_create([
            'http' => [
                'method' => $request->method,
                'header' => implode("\r\n", $lines),
                'timeout' => $request->timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
                'protocol_version' => 1.1,
            ],
        ]);
        $error = null;
        set_error_handler(static function (int $no, string $message) use (&$error): bool {
            $error = $message;
            return true;
        });
        try {
            $handle = fopen($request->url, 'rb', false, $context);
        } finally {
            restore_error_handler();
        }
        if ($handle === false) {
            if ($error !== null && stripos($error, 'timed out') !== false) {
                throw new APITimeoutError(sprintf('Request timed out after %.0f ms', $request->timeout * 1000));
            }
            throw new APIConnectionError('Network error: ' . ($error ?? 'could not connect'));
        }
        $meta = stream_get_meta_data($handle);
        $status = 0;
        $headers = [];
        foreach ($meta['wrapper_data'] ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m)) {
                $status = (int) $m[1];
                $headers = [];
            } elseif (($pos = strpos((string) $line, ':')) !== false) {
                $headers[strtolower(trim(substr((string) $line, 0, $pos)))] = trim(substr((string) $line, $pos + 1));
            }
        }
        if ($status >= 200 && $status < 300) {
            stream_set_timeout($handle, (int) self::STREAM_IDLE_TIMEOUT);
            return new Response($status, $headers, '', $handle);
        }
        $body = (string) stream_get_contents($handle);
        fclose($handle);
        return new Response($status, $headers, $body);
    }
}
