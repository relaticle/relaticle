<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Support\Media\UploadAllowlist;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class ShowMediaController
{
    public function __invoke(Media $media): StreamedResponse
    {
        $name = (string) $media->getCustomProperty('original_name', $media->file_name);
        $disposition = UploadAllowlist::isImage((string) $media->mime_type) ? 'inline' : 'attachment';

        return Storage::disk($media->disk)->response($media->getPathRelativeToRoot(), $name, ['Cache-Control' => 'private, no-store'], $disposition);
    }
}
