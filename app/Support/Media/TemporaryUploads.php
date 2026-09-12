<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Exceptions\UploadException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final readonly class TemporaryUploads
{
    public const string NAME_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}\.[a-z0-9]{2,5}\z/';

    public const string DIRECTORY = 'tmp';

    public static function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('local');

        return $disk;
    }

    public static function newName(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        throw_unless(in_array($extension, UploadAllowlist::extensions(), true), UploadException::mimeNotAllowed($extension));

        return Str::ulid().'.'.$extension;
    }

    public static function path(string $upload): string
    {
        return self::DIRECTORY.'/'.$upload;
    }

    public static function isValidName(string $upload): bool
    {
        return preg_match(self::NAME_PATTERN, $upload) === 1;
    }
}
