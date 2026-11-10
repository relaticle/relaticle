<?php

declare(strict_types=1);

namespace App\Filament\Tables\FilterBar;

use App\Enums\CreationSource;
use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Enums\FilterFieldKind;
use App\Enums\FilterKind;
use App\Models\CustomField;
use App\Models\User;
use App\Queries\CustomFieldFilterSchema;
use App\Queries\EntityFilters;
use App\Queries\FilterDefinition;
use App\Queries\Filters\AssignedToMeFilter;
use App\Support\CustomFields\WorkspaceCustomFields;

final readonly class FilterFieldCatalog
{
    public const string CUSTOM_FIELD_PREFIX = 'custom_fields.';

    /** @return array<string, FilterField> */
    public static function for(CrmEntity $entity, User $user): array
    {
        return once(fn (): array => [...self::nativeFields($entity), ...self::customFields($entity, $user)]);
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

    /** @return array<string, FilterField> */
    private static function nativeFields(CrmEntity $entity): array
    {
        $fields = [];

        foreach (EntityFilters::definitions($entity) as $key => $definition) {
            if ($definition->filterClass === AssignedToMeFilter::class) {
                continue;
            }

            $fields[$key] = new FilterField(
                key: $key,
                label: __("filament/panel.tables.filters.fields.{$key}"),
                icon: self::nativeIcon($key, $definition),
                kind: $definition->kind->filterFieldKind(),
                related: $definition->related,
                options: self::nativeOptions($definition),
                operators: $definition->operators(),
            );
        }

        return $fields;
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
        if ($definition->enumClass !== CreationSource::class) {
            return [];
        }

        return array_map(
            static fn (CreationSource $source): array => ['id' => $source->value, 'label' => (string) $source->getLabel(), 'color' => $source->getColor()],
            CreationSource::cases(),
        );
    }

    /** @return array<string, FilterField> */
    private static function customFields(CrmEntity $entity, User $user): array
    {
        $fields = [];

        foreach (resolve(WorkspaceCustomFields::class)->forEntity($user->currentWorkspace, $entity->value) as $field) {
            $kind = CustomFieldType::tryFrom($field->type)?->filterFieldKind();

            if ($kind === null || ! CustomFieldFilterSchema::isFilterable($field)) {
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
