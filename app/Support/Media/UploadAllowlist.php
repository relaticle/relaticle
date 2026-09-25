<?php

declare(strict_types=1);

namespace App\Support\Media;

final readonly class UploadAllowlist
{
    /** @var array<string, string> */
    public const array MIME_TYPES = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    public static function maxBytes(): int
    {
        return (int) config('media-library.max_file_size');
    }

    /** @return list<string> */
    public static function mimeTypes(): array
    {
        return array_keys(self::MIME_TYPES);
    }

    public static function extensionFor(string $mime): ?string
    {
        return self::MIME_TYPES[$mime] ?? null;
    }

    public static function isImage(string $mime): bool
    {
        return str_starts_with($mime, 'image/') && isset(self::MIME_TYPES[$mime]);
    }

    /** @return list<string> */
    public static function extensions(): array
    {
        return array_values(array_unique([...array_values(self::MIME_TYPES), 'jpeg']));
    }
}
