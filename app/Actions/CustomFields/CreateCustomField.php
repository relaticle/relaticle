<?php

declare(strict_types=1);

namespace App\Actions\CustomFields;

use App\Models\CustomField;
use App\Models\User;
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

        $type = (string) ($data['type'] ?? '');
        $entityType = (string) ($data['entity_type'] ?? '');
        $name = (string) ($data['name'] ?? '');

        abort_unless(
            in_array($type, self::ALLOWED_TYPES, true),
            422,
            "Field type \"{$type}\" is not allowed via chat. Allowed types: ".implode(', ', self::ALLOWED_TYPES).'.',
        );

        abort_unless(
            in_array($entityType, self::VALID_ENTITY_TYPES, true),
            422,
            "Entity type \"{$entityType}\" is not valid. Valid types: ".implode(', ', self::VALID_ENTITY_TYPES).'.',
        );

        $options = is_array($data['options'] ?? null) ? $data['options'] : [];
        $isChoiceType = $this->isChoiceType($type);

        abort_if($isChoiceType && $options === [], 422, "Field type \"{$type}\" requires at least one option.");

        abort_if(! $isChoiceType && $options !== [], 422, "Field type \"{$type}\" does not support options.");

        $teamId = $user->currentTeam->getKey();
        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId($teamId);

        try {
            $maxFields = (int) config('chat.max_custom_fields_per_entity', 50);
            $existingCount = CustomField::query()
                ->withoutGlobalScope(CustomFieldsActivableScope::class)
                ->where('tenant_id', $teamId)
                ->where('entity_type', $entityType)
                ->count();

            abort_if(
                $existingCount >= $maxFields,
                422,
                "Cannot create more than {$maxFields} custom fields per entity type.",
            );

            if ($isChoiceType) {
                $maxOptions = (int) config('chat.max_field_options', 50);
                abort_if(
                    count($options) > $maxOptions,
                    422,
                    "Cannot create more than {$maxOptions} options per field.",
                );
            }

            $code = (string) ($data['code'] ?? '');

            if ($code === '') {
                $code = CodeGenerator::generateUniqueFieldCode($name, $entityType);
            }

            $nextSortOrder = (int) CustomField::query()
                ->withoutGlobalScope(CustomFieldsActivableScope::class)
                ->where('tenant_id', $teamId)
                ->where('entity_type', $entityType)
                ->max('sort_order') + 1;

            $field = DB::transaction(function () use ($teamId, $entityType, $type, $name, $code, $nextSortOrder, $options, $isChoiceType): CustomField {
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

                if ($isChoiceType) {
                    foreach (array_values($options) as $index => $option) {
                        $optionName = is_array($option) ? (string) ($option['name'] ?? '') : (string) $option;
                        $created->options()->create([
                            $tenantKey => $teamId,
                            'name' => $optionName,
                            'sort_order' => $index,
                        ]);
                    }
                }

                return $created;
            });
        } finally {
            TenantContextService::setTenantId($previousTenantId);
        }

        return $field->load('options');
    }

    private function isChoiceType(string $type): bool
    {
        return in_array($type, self::CHOICE_TYPES, true);
    }
}
