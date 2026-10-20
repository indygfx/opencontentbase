<?php
declare(strict_types=1);

namespace Writing;

/**
 * Single storage helper for uploaded images (story covers and character
 * portraits). Files live under data/<directory>/ and are addressed by a
 * bare file name; the storage directories are never exposed directly.
 */
final class ImageStore
{
    public const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    public const MAX_BYTES = 5242880; // 5 MB

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * Validates an uploaded file entry (from $_FILES) and returns the file
     * extension to store it under, or null when the upload is invalid.
     *
     * @param array<string, mixed>|null $file
     */
    public function validateUpload(?array $file): ?string
    {
        if ($file === null || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            return null;
        }
        $ext = self::TYPES[(string)($file['type'] ?? '')] ?? null;
        if ($ext === null || $this->isImage((string)($file['tmp_name'] ?? '')) === false) {
            return null;
        }
        return $ext;
    }

    /**
     * Moves an uploaded file into the storage directory under the given
     * name. Any existing file with that name is replaced. Falls back to
     * a plain rename when move_uploaded_file() refuses the file (CLI/test
     * context, where entries are not real HTTP uploads).
     *
     * @param array<string, mixed> $file
     */
    public function store(array $file, string $directory, string $name): void
    {
        $dir = $this->directoryPath($directory);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $target = $dir . '/' . basename($name);
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || (!@move_uploaded_file($tmp, $target) && !@rename($tmp, $target))) {
            throw new \RuntimeException('Could not store the uploaded file.');
        }
    }

    /** Absolute path of a stored file; returns null when it does not exist. */
    public function path(string $directory, string $name): ?string
    {
        if ($name === '') {
            return null;
        }
        $file = $this->directoryPath($directory) . '/' . basename($name);
        return is_file($file) ? $file : null;
    }

    /**
     * Content-Type for a stored file, or null when the file is missing or
     * its detected MIME type is not a supported image type.
     */
    public function contentType(string $directory, string $name): ?string
    {
        $file = $this->path($directory, $name);
        if ($file === null) {
            return null;
        }
        $mime = (string)(mime_content_type($file) ?: '');
        return isset(self::TYPES[$mime]) ? $mime : null;
    }

    /** Deletes a stored file if it exists; missing files are ignored. */
    public function delete(string $directory, string $name): void
    {
        $file = $this->path($directory, $name);
        if ($file !== null) {
            unlink($file);
        }
    }

    private function directoryPath(string $directory): string
    {
        return rtrim($this->basePath, '/') . '/data/' . trim($directory, '/');
    }

    private function isImage(string $tmpName): bool
    {
        if (!is_file($tmpName)) {
            return false;
        }
        $info = @getimagesize($tmpName);
        return $info !== false;
    }
}
