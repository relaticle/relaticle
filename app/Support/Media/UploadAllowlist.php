<?php

declare(strict_types=1);

namespace App\Support\Media;

use finfo;
use Illuminate\Http\UploadedFile;

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

    /** @var array<string, list<string>> */
    public const array EMAIL_MIME_TYPES = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/vnd.ms-office', 'application/CDFV2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/vnd.ms-office', 'application/CDFV2'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/vnd.ms-office', 'application/CDFV2'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'odp' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
        'csv' => ['text/csv', 'application/csv', 'text/plain'],
        'txt' => ['text/plain'],
        'rtf' => ['text/rtf', 'application/rtf'],
        'ics' => ['text/calendar', 'text/x-vcalendar', 'text/plain'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'heic' => ['image/heic', 'image/heif'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];

    // finfo reports plain text that looks like code (a leading `#include`) as text/x-*.
    private const array SOURCE_LIKE_TEXT_EXTENSIONS = ['txt', 'csv'];

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

    public static function isAllowedEmailAttachment(UploadedFile $file): bool
    {
        $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $mimes = self::EMAIL_MIME_TYPES[$extension] ?? null;

        if ($mimes === null) {
            return false;
        }

        $detected = (string) new finfo(FILEINFO_MIME_TYPE)->buffer((string) $file->get());

        return in_array($detected, $mimes, true)
            || (in_array($extension, self::SOURCE_LIKE_TEXT_EXTENSIONS, true) && str_starts_with($detected, 'text/x-'));
    }

    public static function emailAccept(): string
    {
        return implode(',', array_map(fn (string $extension): string => '.'.$extension, array_keys(self::EMAIL_MIME_TYPES)));
    }
}
