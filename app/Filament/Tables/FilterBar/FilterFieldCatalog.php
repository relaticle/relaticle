<?php

declare(strict_types=1);

namespace App\Filament\Tables\FilterBar;

use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Enums\FilterFieldKind;
use App\Enums\FilterKind;
use App\Models\CustomField;
use App\Models\User;
use App\Queries\CustomFieldFilterSchema;
use App\Queries\EntityFilters;
use App\Queries\FilterDefinition;
use App\Support\CustomFields\WorkspaceCustomFields;
use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Facades\Lang;

final readonly class FilterFieldCatalog
{
    public const string CUSTOM_FIELD_PREFIX = 'custom_fields.';

    /**
     * @param  array<string, string>  $columnLabels
     * @return array<string, FilterField>
     */
    public static function for(CrmEntity $entity, User $user, array $columnLabels = []): array
    {
        $workspaceId = $user->currentWorkspace->getKey();

        // once() keys on its used variables, and one user object can switch workspace within a request.
        return once(fn (): array => [
            ...self::nativeFields($entity, $columnLabels),
            ...self::customFields($entity, $user, $workspaceId),
        ]);
    }

    public static function columnFor(string $key): string
    {
        return match ($key) {
            'creator', 'accountOwner', 'assignees', 'company' => "{$key}.name",
            default => $key,
        };
    }

    public static function columnIcon(string $column): string
    {
        return match ($column) {
            'accountOwner.name' => 'lucide-user',
            'creator.name' => 'lucide-circle-user',
            'assignees.name' => 'lucide-users',
            'company.name' => 'lucide-building-complex',
            'relations' => 'lucide-link',
            'created_at' => 'lucide-calendar',
            'updated_at' => 'lucide-clock',
            'deleted_at' => 'lucide-trash',
            default => 'lucide-text-align-start',
        };
    }

    /**
     * @param  array<string, string>  $columnLabels
     * @return array<string, FilterField>
     */
    private static function nativeFields(CrmEntity $entity, array $columnLabels): array
    {
        $fields = [];

        foreach (EntityFilters::definitions($entity) as $key => $definition) {
            $kind = $definition->fieldKind();

            if ($kind === null) {
                continue;
            }

            $fields[$key] = new FilterField(
                key: $key,
                label: $columnLabels[self::columnFor($key)] ?? self::fallbackLabel($entity, $key),
                icon: self::nativeIcon($key, $definition),
                kind: $kind,
                related: $definition->related,
                options: self::nativeOptions($definition),
                operators: $definition->operators(),
            );
        }

        return $fields;
    }

    private static function fallbackLabel(CrmEntity $entity, string $key): string
    {
        $scoped = "filament/panel.tables.filters.fields.entity.{$entity->value}.{$key}";

        return Lang::has($scoped) ? __($scoped) : __("filament/panel.tables.filters.fields.{$key}");
    }

    private static function nativeIcon(string $key, FilterDefinition $definition): string
    {
        return match (true) {
            $definition->related instanceof CrmEntity => $definition->related->icon(),
            $definition->kind === FilterKind::Enum => 'lucide-sparkles',
            $definition->kind === FilterKind::Computed => 'lucide-hourglass',
            $definition->kind === FilterKind::Members => self::columnIcon("{$key}.name"),
            default => self::columnIcon($key),
        };
    }

    /** @return list<array{id: string, label: string, color: ?string}> */
    private static function nativeOptions(FilterDefinition $definition): array
    {
        if ($definition->enumClass === null) {
            return [];
        }

        return array_map(static function (BackedEnum $case): array {
            $color = $case instanceof HasColor ? $case->getColor() : null;

            return [
                'id' => (string) $case->value,
                'label' => $case instanceof HasLabel && is_string($case->getLabel()) ? $case->getLabel() : $case->name,
                'color' => is_string($color) ? $color : null,
            ];
        }, $definition->enumClass::cases());
    }

    /** @return array<string, FilterField> */
    private static function customFields(CrmEntity $entity, User $user, int|string $workspaceId): array
    {
        $fields = [];

        foreach (resolve(WorkspaceCustomFields::class)->forEntity($user->currentWorkspace, $entity->value) as $field) {
            $kind = CustomFieldType::tryFrom($field->type)?->filterFieldKind();

            if ($kind === null || (string) $field->tenant_id !== (string) $workspaceId || ! CustomFieldFilterSchema::isFilterable($field)) {
                continue;
            }

            $key = self::CUSTOM_FIELD_PREFIX.$field->code;
            $fields[$key] = new FilterField(
                key: $key,
                label: $field->name,
                icon: $field->typeData->icon,
                kind: $kind,
                related: $kind === FilterFieldKind::Record ? CrmEntity::tryFrom((string) $field->lookup_type) : null,
                options: self::customOptions($field, $kind),
                operators: CustomFieldFilterSchema::operatorKeys($field->type),
                type: CustomFieldType::tryFrom($field->type),
                definition: $field,
            );
        }

        return $fields;
    }

    /** @return list<array{id: string, label: string, color: ?string}> */
    private static function customOptions(CustomField $field, FilterFieldKind $kind): array
    {
        if ($kind !== FilterFieldKind::Choice && $kind !== FilterFieldKind::MultiChoice) {
            return [];
        }

        return array_values($field->options
            ->map(static fn ($option): array => ['id' => (string) $option->getKey(), 'label' => (string) $option->name, 'color' => $option->settings->color])
            ->all());
    }
}
