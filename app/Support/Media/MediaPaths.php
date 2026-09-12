<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Enums\CustomFieldType;
use App\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class MediaPaths
{
    private const string PATH_PATTERN = '#^uploads/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/[^/]+$#';

    /** @var Collection<string, Media|null> */
    private Collection $byPath;

    /** @var Collection<string, Media|null> */
    private Collection $byUuid;

    public function __construct()
    {
        $this->byPath = new Collection;
        $this->byUuid = new Collection;
    }

    /** @param iterable<Model> $models */
    public function prime(iterable $models): void
    {
        $wanted = [];

        foreach ($models as $model) {
            if (! $model->relationLoaded('customFieldValues')) {
                continue;
            }

            foreach ($model->getRelation('customFieldValues') as $fieldValue) {
                if (! $fieldValue instanceof CustomFieldValue || ! isset($fieldValue->getRelations()['customField'])) {
                    continue;
                }

                if ($fieldValue->customField->type !== CustomFieldType::FILE_UPLOAD->value) {
                    continue;
                }

                $path = $fieldValue->getValue();
                $uuid = is_string($path) ? $this->uuidFromPath($path) : null;

                if ($uuid !== null) {
                    $wanted[(string) $fieldValue->getAttribute('tenant_id')][$path] = $uuid;
                }
            }
        }

        foreach ($wanted as $teamId => $paths) {
            $missing = array_filter(
                $paths,
                fn (string $uuid, string $path): bool => ! $this->byPath->has($this->pathKey($teamId, $path)),
                ARRAY_FILTER_USE_BOTH,
            );

            if ($missing === []) {
                continue;
            }

            $found = Media::query()
                ->where('custom_properties->team_id', $teamId)
                ->whereIn('uuid', array_values($missing))
                ->get()
                ->keyBy('uuid');

            foreach ($missing as $path => $uuid) {
                $media = $found->get($uuid);
                $media = $media instanceof Media && $media->getPathRelativeToRoot() === $path ? $media : null;

                $this->byPath->put($this->pathKey($teamId, $path), $media);
                $this->byUuid->put($this->uuidKey($teamId, $uuid), $media);
            }
        }
    }

    public function uuidFromPath(string $path): ?string
    {
        return preg_match(self::PATH_PATTERN, $path, $matches) === 1 ? $matches[1] : null;
    }

    public function find(string $teamId, string $path): ?Media
    {
        $key = $this->pathKey($teamId, $path);

        if ($this->byPath->has($key)) {
            return $this->byPath->get($key);
        }

        $uuid = $this->uuidFromPath($path);

        if ($uuid === null) {
            $this->byPath->put($key, null);

            return null;
        }

        $media = $this->findByUuid($teamId, $uuid);

        if (! $media instanceof Media || $media->getPathRelativeToRoot() !== $path) {
            $this->byPath->put($key, null);

            return null;
        }

        $this->byPath->put($key, $media);

        return $media;
    }

    public function findByUuid(string $teamId, string $uuid): ?Media
    {
        $key = $this->uuidKey($teamId, $uuid);

        if ($this->byUuid->has($key)) {
            return $this->byUuid->get($key);
        }

        $media = Media::query()
            ->where('uuid', $uuid)
            ->where('custom_properties->team_id', $teamId)
            ->first();

        $this->byUuid->put($key, $media);

        return $media;
    }

    private function pathKey(string $teamId, string $path): string
    {
        return $teamId.':'.$path;
    }

    private function uuidKey(string $teamId, string $uuid): string
    {
        return $teamId.':'.$uuid;
    }
}
