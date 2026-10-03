<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Relaticle\EmailIntegration\Models\EmailAttachment;

final readonly class ComposerInlineImage
{
    public function previewUrl(User $user, mixed $file): ?string
    {
        $path = $this->readablePath($user, $file);

        if ($path === null) {
            return null;
        }

        return route('email-compose-images.show', ['path' => $path]);
    }

    public function readablePath(User $user, mixed $file): ?string
    {
        if (! is_string($file) || $file === '') {
            return null;
        }

        $path = $this->authorizedPath($user, $file);

        if ($path === null) {
            return null;
        }

        $disk = Storage::disk(EmailAttachment::DISK);

        if (! $disk->exists($path)) {
            return null;
        }

        $mimeType = $disk->mimeType($path);

        if (! is_string($mimeType) || ! str_starts_with(mb_strtolower($mimeType), 'image/')) {
            return null;
        }

        return $path;
    }

    public function authorizedPath(User $user, string $sourcePath): ?string
    {
        $path = $this->normalizeStoragePath($sourcePath);

        if ($path === null) {
            return null;
        }

        $teamId = $user->current_workspace_id;

        if (blank($teamId)) {
            return null;
        }

        $directory = EmailAttachment::composeImagesDirectory((string) $teamId).'/';

        if (! str_starts_with($path, $directory)) {
            return null;
        }

        return $path;
    }

    private function normalizeStoragePath(string $sourcePath): ?string
    {
        $path = str_replace('\\', '/', $sourcePath);
        $path = ltrim($path, '/');

        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            return null;
        }

        return implode('/', $segments);
    }
}
