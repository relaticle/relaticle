<?php

declare(strict_types=1);

namespace App\Actions\CustomFields;

use App\Enums\WorkspaceCapability;
use App\Models\CustomField;
use App\Models\User;
use App\Support\CustomFieldDefinitionValidator;
use App\Support\CustomFieldSettingsSchema;
use Illuminate\Support\Facades\DB;
use Relaticle\CustomFields\Data\FieldSlotData;
use Relaticle\CustomFields\Data\RelationshipDefinitionData;
use Relaticle\CustomFields\Enums\RelationshipCardinality;
use Relaticle\CustomFields\Models\Scopes\CustomFieldsActivableScope;
use Relaticle\CustomFields\Services\Relationships\CreateRelationshipDefinition;
use Relaticle\CustomFields\Services\TenantContextService;
use Relaticle\CustomFields\Support\CodeGenerator;

final readonly class CreateCustomField
{
    /** @var list<string> Types that require user-managed options. */
    public const array CHOICE_TYPES = [
        'select',
        'status',
        'multi-select',
        'radio',
        'checkbox-list',
        'toggle-buttons',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data): CustomField
    {
        abort_unless(
            $user->hasWorkspaceCapability($user->currentWorkspace?->getKey(), WorkspaceCapability::FieldsManage),
            403,
            'Only workspace owners and admins can manage custom field definitions.',
        );

        $workspaceId = $user->currentWorkspace->getKey();
        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId($workspaceId);

        try {
            // Re-validated here, not just at proposal time: a proposal approved after
            // someone else claimed the name must fail rather than write a duplicate.
            $validated = CustomFieldDefinitionValidator::forCreate($user, $data);

            $optionNames = array_column(is_array($validated['options'] ?? null) ? $validated['options'] : [], 'name');
            $attributes = $this->attributes($workspaceId, $validated);

            $field = DB::transaction(function () use ($workspaceId, $attributes, $optionNames, $validated): CustomField {
                $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');

                /** @var CustomField $created */
                $created = CustomField::query()->create([$tenantKey => $workspaceId, ...$attributes]);

                foreach ($optionNames as $index => $optionName) {
                    $created->options()->create([
                        $tenantKey => $workspaceId,
                        'name' => $optionName,
                        'sort_order' => $index,
                    ]);
                }

                if (is_string($validated['lookup_type'] ?? null)) {
                    $this->linkToRecords($created, $validated['lookup_type'], $attributes['settings']->allow_multiple);
                }

                return $created;
            });
        } finally {
            TenantContextService::setTenantId($previousTenantId);
        }

        return $field->load('options');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(int|string $workspaceId, array $validated): array
    {
        $entityType = (string) $validated['entity_type'];
        $type = (string) $validated['type'];
        $name = (string) $validated['name'];
        $code = (string) ($validated['code'] ?? '');
        $settings = is_array($validated['settings'] ?? null) ? $validated['settings'] : [];

        return [
            'entity_type' => $entityType,
            'type' => $type,
            'name' => $name,
            'code' => $code !== '' ? $code : CodeGenerator::generateUniqueFieldCode($name, $entityType),
            'sort_order' => $this->nextSortOrder($workspaceId, $entityType),
            'active' => true,
            'system_defined' => false,
            'validation_rules' => [],
            'settings' => CustomFieldSettingsSchema::apply(
                new CustomField(['type' => $type]),
                CustomFieldSettingsSchema::withTypeDefaults($type, $settings),
            ),
        ];
    }

    private function linkToRecords(CustomField $field, string $targetEntityType, bool $holdsMany): void
    {
        resolve(CreateRelationshipDefinition::class)->execute(new RelationshipDefinitionData(
            code: CodeGenerator::generateUniqueRelationshipCode($field->code),
            fromEntityType: (string) $field->entity_type,
            toEntityType: $targetEntityType,
            cardinality: RelationshipCardinality::ManyToOne->fromSideHolds($holdsMany),
            fromField: new FieldSlotData(name: $field->name, fieldId: $field->getKey()),
        ));
    }

    private function nextSortOrder(int|string $workspaceId, string $entityType): int
    {
        return (int) CustomField::query()
            ->withoutGlobalScope(CustomFieldsActivableScope::class)
            ->where('tenant_id', $workspaceId)
            ->where('entity_type', $entityType)
            ->max('sort_order') + 1;
    }
}
