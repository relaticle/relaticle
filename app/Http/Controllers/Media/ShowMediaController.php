<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Support\Media\UploadAllowlist;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class ShowMediaController
{
    public function __invoke(Media $media): StreamedResponse
    {
        $disk = Storage::disk($media->disk);
        $path = $media->getPathRelativeToRoot();

        abort_unless($disk->exists($path), 404);

        $disposition = UploadAllowlist::isImage((string) $media->mime_type) ? 'inline' : 'attachment';

        return $disk->response($path, $media->name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, (string) $media->name, $this->fallbackName($media)),
        ]);
    }

    /**
     * Symfony rejects an empty ASCII fallback, and a name that transliterates to
     * nothing (Japanese, Arabic, emoji) produces exactly that. The real name still
     * travels in the RFC 5987 `filename*` parameter.
     */
    private function fallbackName(Media $media): string
    {
        $ascii = str_replace('%', '', Str::ascii((string) $media->name));

        if ($ascii !== '') {
            return $ascii;
        }

        $extension = pathinfo((string) $media->file_name, PATHINFO_EXTENSION);

        return $extension === '' ? 'download' : "download.{$extension}";
    }
}
