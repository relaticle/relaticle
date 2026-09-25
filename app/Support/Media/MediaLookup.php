<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Enums\CustomFieldType;
use App\Models\CustomFieldValue;
use Dom\HTMLDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class MediaLookup
{
    private const string UPLOAD_URL = '#/(?:uploads|media)/([0-9a-f-]{36})(?:/|\?|\#|$)#i';

    /** @var array<string, Media|null> */
    private array $byUuid = [];

    /** @param iterable<Model> $models */
    public function prime(iterable $models): void
    {
        $uuids = [];

        foreach ($models as $model) {
            if (! $model->relationLoaded('customFieldValues')) {
                continue;
            }

            foreach ($model->getRelation('customFieldValues') as $fieldValue) {
                if (! $fieldValue instanceof CustomFieldValue || ! $fieldValue->relationLoaded('customField')) {
                    continue;
                }

                array_push($uuids, ...$this->referencedUuids($fieldValue->customField->type, $fieldValue->getValue()));
            }
        }

        $missing = array_filter(array_unique($uuids), fn (string $uuid): bool => ! array_key_exists($uuid, $this->byUuid));

        if ($missing === []) {
            return;
        }

        $found = Media::query()->whereIn('uuid', $missing)->get()->keyBy('uuid');

        foreach ($missing as $uuid) {
            $this->byUuid[$uuid] = $found->get($uuid);
        }
    }

    public function find(string $workspaceId, string $uuid): ?Media
    {
        $uuid = strtolower($uuid);

        if (! array_key_exists($uuid, $this->byUuid)) {
            $this->byUuid[$uuid] = Str::isUuid($uuid) ? Media::query()->where('uuid', $uuid)->first() : null;
        }

        $media = $this->byUuid[$uuid];

        return $media?->workspace_id === $workspaceId ? $media : null;
    }

    /** @return list<string> */
    public function referencedUuids(string $fieldType, mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        return match ($fieldType) {
            CustomFieldType::RICH_EDITOR->value => $this->attachmentUuids($value),
            default => [],
        };
    }

    /** @return list<string> */
    public function attachmentUuids(string $html): array
    {
        $document = HTMLDocument::createFromString('<body>'.$html, LIBXML_NOERROR, 'UTF-8');
        $uuids = [];

        foreach ($document->querySelectorAll('img, a[href]') as $element) {
            $uuid = $element->localName === 'img'
                ? $element->getAttribute('data-id')
                : $this->uuidFromUrl($element->getAttribute('href') ?? '');

            if (is_string($uuid) && Str::isUuid($uuid)) {
                $uuids[] = strtolower($uuid);
            }
        }

        return array_values(array_unique($uuids));
    }

    public function uuidFromUrl(string $url): ?string
    {
        return preg_match(self::UPLOAD_URL, $url, $match) === 1 && Str::isUuid($match[1]) ? strtolower($match[1]) : null;
    }
}
