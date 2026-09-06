<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Concerns;

use App\Support\RecordLinkFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Relaticle\CustomFields\Models\CustomField;
use Relaticle\CustomFields\Models\CustomFieldValue;
use Relaticle\CustomFields\Services\Relationships\LinkReader;

trait FormatsCustomFields
{
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
     * @return array<string, array<int, array{id: string, label: string}>>
     */
    private function formatLinkFields(Model $record): array
    {
        $tenantId = $record->getAttribute('team_id');

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

            $formatted[$field->code] = array_map(
                static fn (int|string $id): array => ['id' => (string) $id, 'label' => (string) $id],
                $reader->orderedIdsFor($record, $definition, $definition->readDirectionFor($field)),
            );
        }

        return $formatted;
    }

    private function resolveFieldValue(CustomFieldValue $fieldValue): mixed
    {
        $customField = $fieldValue->customField;
        $rawValue = $fieldValue->getValue();

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
            'label' => $option !== null ? $option->name : (string) $rawValue,
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
                    'label' => $option !== null ? $option->name : $stringId,
                ];
            })
            ->values()
            ->all();
    }
}
