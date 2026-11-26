<?php

declare(strict_types=1);

namespace BillionSend;

/**
 * A file for a multipart upload (contacts.imports.create and the logo
 * uploads), with the name and content type to send it under. A file field also
 * takes the content itself as a string, or an open stream resource.
 */
final class FileUpload
{
    /**
     * @param string $content the file's bytes
     * @param string|null $filename the file name sent with the upload; by
     *   default logo.png, logo.jpg or logo.webp for such an image, otherwise
     *   the field's name
     * @param string|null $contentType the part's content type; by default judged
     *   from an image's bytes, then from the file name
     */
    public function __construct(
        public readonly string $content,
        public readonly ?string $filename = null,
        public readonly ?string $contentType = null,
    ) {
    }

    /**
     * Reads a local file; it keeps its own name unless you give another.
     *
     * @throws \InvalidArgumentException when the file cannot be read
     */
    public static function fromPath(string $path, ?string $filename = null, ?string $contentType = null): self
    {
        $content = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw new \InvalidArgumentException("Cannot read the file $path");
        }
        return new self($content, $filename ?? basename($path), $contentType);
    }
}
