<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Support\ComposerInlineImage;

final readonly class ComposerInlineImageController
{
    public function __construct(private ComposerInlineImage $images) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $rawPath = $request->query('path');
        $path = $this->images->readablePath($user, is_string($rawPath) ? $rawPath : '');

        abort_if($path === null, 404);

        $disk = Storage::disk(EmailAttachment::DISK);
        $mimeType = $disk->mimeType($path);

        return response($disk->get($path) ?? '', 200, [
            'Content-Type' => is_string($mimeType) ? $mimeType : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
