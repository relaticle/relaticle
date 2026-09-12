<?php

declare(strict_types=1);

namespace App\Actions\Upload;

use App\Enums\MediaCollection;
use App\Enums\UploadSource;
use App\Exceptions\UploadException;
use App\Models\Team;
use App\Models\User;
use App\Support\Media\UploadAllowlist;
use finfo;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class StorePendingUpload
{
    public function execute(User $user, Team $team, string $path, string $originalName, UploadSource $source): Media
    {
        abort_unless($user->belongsToTeam($team), 403);

        throw_unless(is_file($path), UploadException::notFound());

        $size = (int) filesize($path);

        throw_if($size > UploadAllowlist::maxBytes(), UploadException::tooLarge(UploadAllowlist::maxBytes()));

        $mime = (string) new finfo(FILEINFO_MIME_TYPE)->file($path);
        $extension = UploadAllowlist::extensionFor($mime);

        throw_if($extension === null, UploadException::mimeNotAllowed($mime));

        $originalName = $this->safeOriginalName($originalName, $extension);

        return $team->addMedia($path)
            ->usingFileName(Str::ulid().'.'.$extension)
            ->usingName(pathinfo($originalName, PATHINFO_FILENAME))
            ->withCustomProperties([
                'team_id' => $team->getKey(),
                'uploaded_by' => $user->getKey(),
                'source' => $source->value,
                'original_name' => $originalName,
            ])
            ->toMediaCollection(MediaCollection::PendingUploads->value);
    }

    private function safeOriginalName(string $originalName, string $extension): string
    {
        $name = Str::of($originalName)
            ->replace('\\', '/')
            ->afterLast('/')
            ->replaceMatches('/[\x00-\x1F\x7F]/u', '')
            ->trim()
            ->toString();

        return $name === '' ? "upload.{$extension}" : $name;
    }
}
