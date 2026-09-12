<?php

declare(strict_types=1);

namespace App\Support\Media;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class MediaPaths
{
    private const string PATH_PATTERN = '#^uploads/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/[^/]+$#';

    public function uuidFromPath(string $path): ?string
    {
        return preg_match(self::PATH_PATTERN, $path, $matches) === 1 ? $matches[1] : null;
    }

    public function find(string $teamId, string $path): ?Media
    {
        $uuid = $this->uuidFromPath($path);

        if ($uuid === null) {
            return null;
        }

        $media = $this->findByUuid($teamId, $uuid);

        if (! $media instanceof Media || $media->getPathRelativeToRoot() !== $path) {
            return null;
        }

        return $media;
    }

    public function findByUuid(string $teamId, string $uuid): ?Media
    {
        return Media::query()
            ->where('uuid', $uuid)
            ->where('custom_properties->team_id', $teamId)
            ->first();
    }
}
