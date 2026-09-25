<?php

declare(strict_types=1);

namespace App\Actions\Upload;

use App\Enums\MediaCollection;
use App\Enums\UploadSource;
use App\Exceptions\UploadException;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Media\UploadAllowlist;
use finfo;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class StorePendingUpload
{
    public function execute(User $user, Workspace $workspace, string $path, string $originalName, UploadSource $source): Media
    {
        abort_unless($user->belongsToWorkspace($workspace), 403);

        throw_unless(is_file($path), UploadException::notFound());

        throw_if((int) filesize($path) > UploadAllowlist::maxBytes(), UploadException::tooLarge(UploadAllowlist::maxBytes()));

        $mime = (string) new finfo(FILEINFO_MIME_TYPE)->file($path);
        $extension = UploadAllowlist::extensionFor($mime);

        throw_if($extension === null, UploadException::mimeNotAllowed($mime));

        return $workspace->addMedia($path)
            ->usingFileName(Str::ulid().'.'.$extension)
            ->usingName($this->safeName($originalName, $extension))
            ->withAttributes(['workspace_id' => $workspace->getKey()])
            ->withCustomProperties([
                'uploaded_by' => $user->getKey(),
                'source' => $source->value,
            ])
            ->toMediaCollection(MediaCollection::PendingUploads->value);
    }

    private function safeName(string $originalName, string $extension): string
    {
        $name = Str::of($originalName)
            ->replace('\\', '/')
            ->afterLast('/')
            ->replaceMatches('/[\x00-\x1F\x7F]/u', '')
            ->trim()
            ->limit(255, '')
            ->toString();

        return $name === '' ? "upload.{$extension}" : $name;
    }
}
