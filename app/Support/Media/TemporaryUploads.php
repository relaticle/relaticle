<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Exceptions\UploadException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final readonly class TemporaryUploads
{
    public const string NAME_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}\.[0-9A-HJKMNP-TV-Z]{26}\.[a-z0-9-]{1,60}\.[a-z0-9]{2,5}\z/i';

    public const int MAX_NAME_LENGTH = 120;

    public const string DIRECTORY = 'tmp';

    public static function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('local');

        return $disk;
    }

    public static function newName(string $filename, string $workspaceId): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        throw_unless(in_array($extension, UploadAllowlist::extensions(), true), UploadException::mimeNotAllowed($extension));

        return strtoupper($workspaceId).'.'.Str::ulid().'.'.self::slug($filename).'.'.$extension;
    }

    public static function displayName(string $upload): string
    {
        $parts = explode('.', $upload);

        return $parts[2].'.'.$parts[3];
    }

    private static function slug(string $filename): string
    {
        $slug = rtrim(Str::limit(Str::slug(pathinfo($filename, PATHINFO_FILENAME)), 60, ''), '-');

        return $slug === '' ? 'file' : $slug;
    }

    public static function path(string $upload): string
    {
        return self::DIRECTORY.'/'.$upload;
    }

    public static function isValidName(string $upload): bool
    {
        return preg_match(self::NAME_PATTERN, $upload) === 1;
    }

    public static function belongsToWorkspace(string $upload, string $workspaceId): bool
    {
        if (! self::isValidName($upload)) {
            return false;
        }

        return hash_equals(strtoupper($workspaceId), explode('.', $upload, 2)[0]);
    }
}
