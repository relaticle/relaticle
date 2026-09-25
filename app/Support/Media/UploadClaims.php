<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Enums\CustomFieldType;
use App\Enums\MediaCollection;
use App\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Relaticle\CustomFields\Models\CustomField;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class UploadClaims
{
    public function __construct(private MediaLookup $lookup) {}

    public function assertClaimable(CustomFieldValue $value): void
    {
        if (! $value->isDirty(['string_value', 'text_value'])) {
            return;
        }

        $field = $value->getRelationValue('customField');

        if (! $field instanceof CustomField) {
            return;
        }

        $referenced = $this->lookup->referencedUuids($field->type, $value->getValue());

        if ($referenced === []) {
            return;
        }

        $workspaceId = (string) $value->getAttribute('tenant_id');
        $uploads = Media::query()->where('workspace_id', $workspaceId)
            ->whereIn('uuid', $referenced)->orderBy('uuid')->lockForUpdate()->get()->keyBy('uuid');

        foreach ($referenced as $uuid) {
            $media = $uploads->get($uuid);

            if (! $media instanceof Media) {
                continue;
            }

            $claimable = $media->collection_name === MediaCollection::PendingUploads->value
                || ($media->collection_name === MediaCollection::Attachments->value
                    && $media->model_type === $value->getAttribute('entity_type')
                    && (string) $media->model_id === (string) $value->getAttribute('entity_id'));

            throw_unless($claimable, ValidationException::withMessages([
                "custom_fields.{$field->code}" => __('validation.custom_field.upload', ['field' => $field->name]),
            ]));
        }
    }

    public function sync(CustomFieldValue $value): void
    {
        $field = $value->getRelationValue('customField');

        if (! $field instanceof CustomField) {
            return;
        }

        if ($field->type !== CustomFieldType::RICH_EDITOR->value) {
            return;
        }

        $entity = $value->entity;

        if (! $entity instanceof HasMedia) {
            return;
        }

        $referenced = $this->lookup->referencedUuids($field->type, $value->getValue());

        Media::query()
            ->where('workspace_id', $value->getAttribute('tenant_id'))
            ->where('collection_name', MediaCollection::PendingUploads->value)
            ->whereIn('uuid', $referenced)
            ->update([
                'model_type' => $entity->getMorphClass(),
                'model_id' => $entity->getKey(),
                'collection_name' => MediaCollection::Attachments->value,
            ]);

        DB::afterCommit(function () use ($entity, $value): void {
            DB::transaction(function () use ($entity, $value): void {
                $entity->newQueryWithoutScopes()->whereKey($entity->getKey())->lockForUpdate()->first();

                $entity->media()
                    ->where('collection_name', MediaCollection::Attachments->value)
                    ->whereNotIn('uuid', $this->referencedAcrossRecord($entity, (string) $value->getAttribute('tenant_id')))
                    ->get()
                    ->each->delete();
            });
        });
    }

    /**
     * Attachments belong to the record, not to one field, so a release has to read every
     * rich editor value on it. Reading one would delete the other fields' files.
     *
     * @return list<string>
     */
    private function referencedAcrossRecord(HasMedia $entity, string $workspaceId): array
    {
        $values = CustomFieldValue::query()->withoutGlobalScopes()
            ->where('tenant_id', $workspaceId)
            ->where('entity_type', $entity->getMorphClass())
            ->where('entity_id', $entity->getKey())
            ->whereHas('customField', fn (Builder $query): Builder => $query->withoutGlobalScopes()->where('type', CustomFieldType::RICH_EDITOR->value))
            ->with(['customField' => fn (Relation $query): Relation => $query->withoutGlobalScopes()])
            ->get();

        $uuids = [];

        foreach ($values as $each) {
            $field = $each->getRelationValue('customField');

            if ($field instanceof CustomField) {
                array_push($uuids, ...$this->lookup->referencedUuids($field->type, $each->getValue()));
            }
        }

        return array_values(array_unique($uuids));
    }
}
