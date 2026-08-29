<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services\Tools;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\User;
use Relaticle\Chat\Support\ProposalCoreFields;
use Relaticle\Chat\Support\TeamMembersContext;
use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\CustomFields\Facades\CustomFieldsType;
use Relaticle\CustomFields\Models\CustomFieldOption;
use Relaticle\CustomFields\Services\ValidationService;

/**
 * Produces the structured, editable field schema for a single create-proposal
 * record (one entity, or one item of a batch). The frontend renders a per-type
 * editor from each entry; choice fields expose raw option IDs and the matching
 * options list so the edit PATCH can re-validate without a discovery round-trip.
 */
final readonly class ProposalFieldSchemaDescriber
{
    /**
     * @param  array<string, mixed>  $record  clean action_data record (single, or one batch item)
     * @return list<array{code: string, label: string, kind: string, value: mixed, options?: list<array{id: string, label: string}>, required: bool}>
     */
    public function describe(User $user, string $entityType, array $record): array
    {
        return array_merge(
            $this->coreFields($user, $entityType, $record),
            $this->customFields($user, $entityType, $record),
        );
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<array{code: string, label: string, kind: string, value: mixed, options?: list<array{id: string, label: string}>, required: bool}>
     */
    private function coreFields(User $user, string $entityType, array $record): array
    {
        $nameKey = ProposalCoreFields::titleKey($entityType);
        $nameLabel = $nameKey === 'title' ? 'Title' : 'Name';

        $fields = [
            [
                'code' => $nameKey,
                'label' => $nameLabel,
                'kind' => 'text',
                'value' => (string) ($record[$nameKey] ?? ''),
                'required' => true,
            ],
        ];

        if ($entityType === 'company') {
            $owner = $record['account_owner_id'] ?? null;

            $fields[] = [
                'code' => 'account_owner_id',
                'label' => 'Account Owner',
                'kind' => 'select',
                'value' => $owner === null ? null : (string) $owner,
                'options' => array_map(
                    fn (array $member): array => ['id' => $member['id'], 'label' => $member['name']],
                    TeamMembersContext::for($user),
                ),
                'required' => false,
            ];
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<array{code: string, label: string, kind: string, value: mixed, options?: list<array{id: string, label: string}>, required: bool}>
     */
    private function customFields(User $user, string $entityType, array $record): array
    {
        $customFields = is_array($record['custom_fields'] ?? null) ? $record['custom_fields'] : [];

        $fields = CustomField::query()
            ->where('tenant_id', $user->currentTeam->getKey())
            ->where('entity_type', $entityType)
            ->active()
            ->orderBy('code')
            ->with(['options:id,custom_field_id,name'])
            ->get();

        $rows = [];

        foreach ($fields as $field) {
            $dataType = CustomFieldsType::getFieldType($field->type)?->dataType;

            if ($this->isDeferred($field, $dataType)) {
                continue;
            }

            $kind = $this->kindFor($field, $dataType);

            if ($kind === null) {
                continue;
            }

            $row = [
                'code' => $field->code,
                'label' => $field->name,
                'kind' => $kind,
                'value' => $customFields[$field->code] ?? null,
                'required' => $this->isRequired($field),
            ];

            if (in_array($kind, ['select', 'multiselect'], true)) {
                $row['options'] = array_values(array_map(
                    fn (CustomFieldOption $option): array => [
                        'id' => (string) $option->id,
                        'label' => (string) $option->name,
                    ],
                    $field->options->all(),
                ));
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function isDeferred(CustomField $field, ?FieldDataType $dataType): bool
    {
        return $field->type === CustomFieldType::FILE_UPLOAD->value
            || $dataType === FieldDataType::FILE
            || $field->type === CustomFieldType::RECORD->value
            || $field->lookup_type !== null;
    }

    private function kindFor(CustomField $field, ?FieldDataType $dataType): ?string
    {
        if ($field->type === CustomFieldType::LINK->value) {
            return 'link';
        }

        return match ($dataType) {
            FieldDataType::SINGLE_CHOICE => 'select',
            FieldDataType::MULTI_CHOICE => 'multiselect',
            FieldDataType::BOOLEAN => 'toggle',
            FieldDataType::DATE, FieldDataType::DATE_TIME => 'date',
            FieldDataType::NUMERIC, FieldDataType::FLOAT => 'number',
            FieldDataType::TEXT => 'textarea',
            FieldDataType::STRING => 'text',
            default => null,
        };
    }

    /**
     * Delegated to the custom-fields package rather than re-derived here.
     * `validation_rules` casts to a key-value collection (`['required' => true]`),
     * so the old `['name' => 'required']` scan matched nothing and marked every
     * field optional. The dock then built its schema without ->required(), and a
     * cleared value was refused at the action layer after the card called it
     * optional. Commit 5e43ca287 fixed the same predicate on the MCP path.
     */
    private function isRequired(CustomField $field): bool
    {
        return resolve(ValidationService::class)->isRequired($field);
    }
}
