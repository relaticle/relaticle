<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Enums\CustomFieldType;
use App\Enums\MediaCollection;
use App\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class UploadClaims
{
    public function __construct(private MediaPaths $paths) {}

    public function sync(CustomFieldValue $value): void
    {
        $field = $value->customField;

        // @phpstan-ignore identical.alwaysFalse (the customField relation can resolve to null for an orphaned value row)
        if ($field === null) {
            return;
        }

        $referenced = match ($field->type) {
            CustomFieldType::FILE_UPLOAD->value => $this->fileUuids($value->getValue()),
            CustomFieldType::RICH_EDITOR->value => $this->imageUuids($value->getValue()),
            default => null,
        };

        if ($referenced === null) {
            return;
        }

        $entity = $value->entity;

        if (! $entity instanceof HasMedia) {
            return;
        }

        $collection = MediaCollection::forCustomField($field->code);
        $teamId = (string) $value->getAttribute('tenant_id');

        Media::query()
            ->where('custom_properties->team_id', $teamId)
            ->where('collection_name', MediaCollection::PendingUploads->value)
            ->whereIn('uuid', $referenced)
            ->get()
            ->each(function (Media $media) use ($entity, $collection): void {
                $media->model()->associate($entity);
                $media->collection_name = $collection;
                $media->save();
            });

        DB::afterCommit(function () use ($entity, $collection, $referenced): void {
            $entity->media()
                ->where('collection_name', $collection)
                ->whereNotIn('uuid', $referenced)
                ->get()
                ->each(fn (Model $media): ?bool => $media->delete());
        });
    }

    /** @return list<string> */
    private function fileUuids(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        $uuid = $this->paths->uuidFromPath($value);

        return $uuid === null ? [] : [$uuid];
    }

    /** @return list<string> */
    private function imageUuids(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        preg_match_all('/data-id="([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})"/', $value, $matches);

        return array_values(array_unique($matches[1]));
    }
}
