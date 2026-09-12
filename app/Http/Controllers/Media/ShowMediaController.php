<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Support\Media\UploadAllowlist;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class ShowMediaController
{
    public function __invoke(Request $request, Media $media): StreamedResponse
    {
        return UploadAllowlist::isImage((string) $media->mime_type)
            ? $media->toInlineResponse($request)
            : $media->toResponse($request);
    }
}
