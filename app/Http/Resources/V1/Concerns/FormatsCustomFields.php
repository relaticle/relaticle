<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Concerns;

use App\Enums\CustomFieldType;
use App\Support\CustomFields\RecordNameResolver;
use App\Support\Media\MediaLookup;
use App\Support\Media\RichContentAttachments;
use App\Support\RecordLinkFields;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Relaticle\CustomFields\Models\CustomField;
use Relaticle\CustomFields\Models\CustomFieldValue;
use Relaticle\CustomFields\Services\Relationships\LinkReader;

trait FormatsCustomFields
{
    public static function collection(mixed $resource): AnonymousResourceCollection
    {
        $records = $resource instanceof Paginator ? $resource->items() : $resource;

        resolve(RecordNameResolver::class)->prime($records);
        resolve(MediaLookup::class)->prime($records);

        return parent::collection($resource);
    }

    protected function formatCustomFields(Model $record): \stdClass
    {
        if (! $record->relationLoaded('customFieldValues')) {
            return new \stdClass;
        }

        $result = $this->formatLinkFields($record);

        $result += $record->getRelation('customFieldValues')
            // Skip orphaned values whose custom field was deleted: the eager-loaded relation is null.
            ->filter(fn (CustomFieldValue $fieldValue): bool => isset($fieldValue->getRelations()['customField']))
            ->mapWithKeys(fn (CustomFieldValue $fieldValue): array => [
                $fieldValue->customField->code => $this->resolveFieldValue($fieldValue),
            ])
            ->all();

        return (object) $result;
    }

    /**
     * A field that links records keeps its targets in the edge ledger rather than in a
     * value row, so it is read from the links and rendered in the shape every other
     * multi-choice field uses.
     *
     * @return array<string, array<int, array{id: string, name: string|null}>|null>
     */
    private function formatLinkFields(Model $record): array
    {
        $tenantId = $record->getAttribute('workspace_id');

        if (! is_string($tenantId)) {
            return [];
        }

        $fields = resolve(RecordLinkFields::class)->forEntity($tenantId, $record->getMorphClass());

        if ($fields === []) {
            return [];
        }

        $record->loadMissing(['outgoingLinks', 'incomingLinks']);

        $reader = resolve(LinkReader::class);
        $formatted = [];

        foreach ($fields as $field) {
            $definition = $field->relationshipDefinitionOrFail();

            // The ledger holds ids; a reader wants the record's name beside each, in the
            // same id/name shape a record field has always been serialised in.
            $ids = $reader->orderedIdsFor($record, $definition, $definition->readDirectionFor($field));

            $formatted[$field->code] = $ids === []
                ? null
                : $this->resolveRecordValue($field, $ids);
        }

        return $formatted;
    }

    private function resolveFieldValue(CustomFieldValue $fieldValue): mixed
    {
        $customField = $fieldValue->customField;
        $rawValue = $fieldValue->getValue();

        if ($customField->type === CustomFieldType::RECORD->value) {
            return $this->resolveRecordValue($customField, $rawValue);
        }

        if ($customField->type === CustomFieldType::RICH_EDITOR->value && is_string($rawValue)) {
            return RichContentAttachments::forWorkspace((string) $fieldValue->getAttribute('tenant_id'))->rewriteAttachmentUrls($rawValue);
        }

        if (! $customField->typeData->dataType->isChoiceField()) {
            return $rawValue;
        }

        if ($customField->typeData->dataType->isMultiChoiceField()) {
            return $this->resolveMultiChoiceValue($customField, $rawValue);
        }

        return $this->resolveSingleChoiceValue($customField, $rawValue);
    }

    /**
     * @return array{id: string, label: string}|null
     */
    private function resolveSingleChoiceValue(CustomField $customField, mixed $rawValue): ?array
    {
        if ($rawValue === null) {
            return null;
        }

        $option = $customField->options->firstWhere('id', $rawValue);

        return [
            'id' => (string) $rawValue,
            'label' => $option->name ?? (string) $rawValue,
        ];
    }

    /**
     * @return array<int, array{id: string, label: string}>
     */
    private function resolveMultiChoiceValue(CustomField $customField, mixed $rawValue): array
    {
        $values = $rawValue instanceof Collection ? $rawValue->all() : (array) ($rawValue ?? []);

        return collect($values)
            ->filter(fn (mixed $value): bool => is_string($value) || is_numeric($value))
            ->map(function (mixed $optionId) use ($customField): array {
                $stringId = (string) $optionId;
                $option = $customField->options->firstWhere('id', $optionId);

                return [
                    'id' => $stringId,
                    'label' => $option->name ?? $stringId,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: string, name: ?string}>|null
     */
    private function resolveRecordValue(CustomField $customField, mixed $rawValue): ?array
    {
        if ($rawValue === null) {
            return null;
        }

        return resolve(RecordNameResolver::class)->resolve((string) $customField->targetEntityType(), $rawValue);
    }
}
