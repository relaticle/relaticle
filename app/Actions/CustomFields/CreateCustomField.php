<?php

declare(strict_types=1);

namespace App\Actions\CustomFields;

use App\Models\CustomField;
use App\Models\User;
use App\Support\CustomFieldDefinitionValidator;
use Illuminate\Support\Facades\DB;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Models\Scopes\CustomFieldsActivableScope;
use Relaticle\CustomFields\Services\TenantContextService;
use Relaticle\CustomFields\Support\CodeGenerator;

final readonly class CreateCustomField
{
    /** @var list<string> */
    public const array ALLOWED_TYPES = [
        'text',
        'number',
        'email',
        'phone',
        'link',
        'textarea',
        'checkbox',
        'checkbox-list',
        'date',
        'date-time',
        'select',
        'multi-select',
        'tags-input',
        'toggle',
        'toggle-buttons',
        'radio',
        'color-picker',
    ];

    /** @var list<string> Types that require user-managed options. */
    public const array CHOICE_TYPES = [
        'select',
        'multi-select',
        'radio',
        'checkbox-list',
        'toggle-buttons',
    ];

    /** @var list<string> */
    public const array VALID_ENTITY_TYPES = [
        'company',
        'people',
        'opportunity',
        'task',
        'note',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data): CustomField
    {
        abort_unless($user->ownsTeam($user->currentTeam), 403, 'Only team owners can manage custom field definitions.');

        $teamId = $user->currentTeam->getKey();
        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId($teamId);

        try {
            // Re-validated here, not just at proposal time: a proposal approved after
            // someone else claimed the name must fail rather than write a duplicate.
            $validated = CustomFieldDefinitionValidator::forCreate($user, $data);

            $entityType = (string) $validated['entity_type'];
            $type = (string) $validated['type'];
            $name = (string) $validated['name'];
            $optionNames = array_column(is_array($validated['options'] ?? null) ? $validated['options'] : [], 'name');

            $code = (string) ($validated['code'] ?? '');

            if ($code === '') {
                $code = CodeGenerator::generateUniqueFieldCode($name, $entityType);
            }

            $nextSortOrder = (int) CustomField::query()
                ->withoutGlobalScope(CustomFieldsActivableScope::class)
                ->where('tenant_id', $teamId)
                ->where('entity_type', $entityType)
                ->max('sort_order') + 1;

            $field = DB::transaction(function () use ($teamId, $entityType, $type, $name, $code, $nextSortOrder, $optionNames): CustomField {
                $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');

                /** @var CustomField $created */
                $created = CustomField::query()->create([
                    $tenantKey => $teamId,
                    'entity_type' => $entityType,
                    'type' => $type,
                    'name' => $name,
                    'code' => $code,
                    'sort_order' => $nextSortOrder,
                    'active' => true,
                    'system_defined' => false,
                    'validation_rules' => [],
                    'settings' => new CustomFieldSettingsData,
                ]);

                foreach ($optionNames as $index => $optionName) {
                    $created->options()->create([
                        $tenantKey => $teamId,
                        'name' => $optionName,
                        'sort_order' => $index,
                    ]);
                }

                return $created;
            });
        } finally {
            TenantContextService::setTenantId($previousTenantId);
        }

        return $field->load('options');
    }
}
