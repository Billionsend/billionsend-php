<?php

declare(strict_types=1);

namespace BillionSend;

use BillionSend\Exception\BillionSendError;

/**
 * Encodes a multipart/form-data body.
 *
 * @internal
 */
final class Multipart
{
    private const TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'csv' => 'text/csv',
    ];

    /**
     * A file field takes a FileUpload, the content as a string or an open
     * stream. Every other field is text: an array is sent as JSON, a boolean as
     * true / false. A null or an empty array leaves the field out.
     *
     * @param array<mixed> $fields
     * @param list<string> $fileFields
     * @return array{0: string, 1: string} the body and its Content-Type
     * @throws \InvalidArgumentException for a value a field cannot carry
     */
    public static function encode(array $fields, array $fileFields): array
    {
        $boundary = 'billionsend-' . bin2hex(random_bytes(16));
        $body = '';
        foreach ($fields as $name => $value) {
            if ($value === null || $value === []) {
                continue;
            }
            $name = (string) $name;
            $disposition = 'Content-Disposition: form-data; name="' . self::quoted($name) . '"';
            if (in_array($name, $fileFields, true)) {
                [$content, $filename, $type] = self::file($name, $value);
                $disposition .= '; filename="' . self::quoted($filename) . "\"\r\nContent-Type: $type";
            } else {
                $content = self::text($name, $value);
            }
            $body .= "--$boundary\r\n$disposition\r\n\r\n$content\r\n";
        }
        return [$body . "--$boundary--\r\n", "multipart/form-data; boundary=$boundary"];
    }

    /**
     * @return array{0: string, 1: string, 2: string} content, file name, content type
     */
    private static function file(string $field, mixed $value): array
    {
        if (is_string($value)) {
            $value = new FileUpload($value);
        } elseif (is_resource($value) && get_resource_type($value) === 'stream') {
            $meta = stream_get_meta_data($value);
            $content = stream_get_contents($value);
            if ($content === false) {
                throw new \InvalidArgumentException("Cannot read the stream of the field \"$field\"");
            }
            $local = ($meta['wrapper_type'] ?? '') === 'plainfile' && isset($meta['uri']);
            $value = new FileUpload($content, $local ? basename($meta['uri']) : null);
        }
        if (!$value instanceof FileUpload) {
            throw new \InvalidArgumentException("The field \"$field\" takes a BillionSend\\FileUpload, a string or a stream");
        }
        $image = self::imageExtension($value->content);
        $filename = $value->filename ?? ($image === null ? $field : "logo.$image");
        $type = $value->contentType
            ?? ($image === null ? null : self::TYPES[$image])
            ?? self::TYPES[strtolower(pathinfo($filename, PATHINFO_EXTENSION))]
            ?? 'application/octet-stream';
        if (!preg_match('/^[\x20-\x7E]+$/', $type)) {
            throw new BillionSendError('The content type of an upload must be ASCII text without control characters', 'invalid_header');
        }
        return [$value->content, $filename, $type];
    }

    private static function text(string $field, mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }
        throw new \InvalidArgumentException("The field \"$field\" takes text or an array");
    }

    /** Which of the accepted image formats the first bytes are, by their magic numbers. */
    private static function imageExtension(string $content): ?string
    {
        return match (true) {
            str_starts_with($content, "\x89PNG\r\n\x1a\n") => 'png',
            str_starts_with($content, "\xff\xd8\xff") => 'jpg',
            str_starts_with($content, 'RIFF') && substr($content, 8, 4) === 'WEBP' => 'webp',
            default => null,
        };
    }

    /** A name between quotes: nothing in it may end the quote or the header line. */
    private static function quoted(string $name): string
    {
        return str_replace(['"', "\r", "\n"], ['%22', '%0D', '%0A'], $name);
    }
}
