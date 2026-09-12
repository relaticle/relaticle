<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mcp;

use App\Support\Media\TemporaryUploads;
use App\Support\Media\UploadAllowlist;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ReceiveUploadController
{
    public function __invoke(Request $request, string $upload): Response
    {
        abort_unless(TemporaryUploads::isValidName($upload), Response::HTTP_NOT_FOUND);

        $length = $request->header('Content-Length');

        abort_if(! is_numeric($length) || (int) $length < 1, Response::HTTP_LENGTH_REQUIRED);
        abort_if((int) $length > UploadAllowlist::maxBytes(), Response::HTTP_REQUEST_ENTITY_TOO_LARGE);

        $disk = TemporaryUploads::disk();
        $path = TemporaryUploads::path($upload);
        $body = $request->getContent(asResource: true);

        abort_unless(is_resource($body), Response::HTTP_LENGTH_REQUIRED);

        $disk->writeStream($path, $body);

        if ((int) $disk->size($path) > UploadAllowlist::maxBytes()) {
            $disk->delete($path);

            abort(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        return response()->noContent();
    }
}
