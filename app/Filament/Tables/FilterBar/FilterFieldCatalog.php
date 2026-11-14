<?php

declare(strict_types=1);

namespace App\Filament\Tables\FilterBar;

use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Enums\FilterFieldKind;
use App\Enums\FilterKind;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Crm\StoredTagsQuery;
use App\Queries\CustomFieldFilterSchema;
use App\Queries\EntityFilters;
use App\Queries\FilterDefinition;
use App\Support\CustomFields\WorkspaceCustomFields;
use BackedEnum;
use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Support\Facades\Lang;
use Relaticle\CustomFields\Enums\CustomFieldsFeature;
use Relaticle\CustomFields\FeatureSystem\FeatureManager;

final readonly class FilterFieldCatalog
{
    public const string CUSTOM_FIELD_PREFIX = 'custom_fields.';

    public const int MAX_TAG_OPTIONS = 200;

    /**
     * @param  array<string, string>  $columnLabels
     * @return array<string, FilterField>
     */
    public static function for(CrmEntity $entity, User $user, array $columnLabels = []): array
    {
        $workspace = $user->currentWorkspace;

        // once() keys on its used variables, and one user object can switch workspace within a request.
        $fields = once(fn (): array => [
            ...self::nativeFields($entity),
            ...self::customFields($entity, $workspace),
        ]);

        return array_map(static function (FilterField $field) use ($columnLabels): FilterField {
            $label = $columnLabels[self::columnFor($field->key)] ?? null;

            return $label === null || $label === $field->label ? $field : $field->labelled($label);
        }, $fields);
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
            'name', 'title' => 'lucide-type',
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
            $kind = $definition->fieldKind();

            if ($kind === null) {
                continue;
            }

            $fields[$key] = new FilterField(
                key: $key,
                entity: $entity,
                label: self::fallbackLabel($entity, $key),
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
                'color' => self::cssColor($color),
            ];
        }, $definition->enumClass::cases());
    }

    /** @return array<string, FilterField> */
    private static function customFields(CrmEntity $entity, Workspace $workspace): array
    {
        $fields = [];

        foreach (resolve(WorkspaceCustomFields::class)->forEntity($workspace, $entity->value) as $field) {
            $type = CustomFieldType::tryFrom($field->type);
            $kind = $type?->filterFieldKind();

            if ($type === null || $kind === null || ! CustomFieldFilterSchema::isFilterable($field)) {
                continue;
            }

            $key = self::CUSTOM_FIELD_PREFIX.$field->code;
            $fields[$key] = new FilterField(
                key: $key,
                entity: $entity,
                label: $field->name,
                icon: $type->icon(),
                kind: $kind,
                related: $kind === FilterFieldKind::Record ? CrmEntity::tryFrom((string) $field->lookup_type) : null,
                options: self::customOptions($field, $kind),
                operators: CustomFieldFilterSchema::operatorKeys($field->type),
                type: $type,
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

        if ($field->type === CustomFieldType::TAGS_INPUT->value) {
            return self::tagOptions($field);
        }

        $colored = self::showsOptionColors($field);

        return array_values($field->options
            ->map(static fn (CustomFieldOption $option): array => ['id' => (string) $option->getKey(), 'label' => (string) $option->name, 'color' => $colored ? self::cssColor($option->settings->color) : null])
            ->all());
    }

    /** @return list<array{id: string, label: string, color: ?string}> */
    private static function tagOptions(CustomField $field): array
    {
        $colored = self::showsOptionColors($field);
        $tags = [];

        foreach ($field->options as $option) {
            $name = trim((string) $option->name);
            $tags[mb_strtolower($name)] ??= ['id' => $name, 'label' => $name, 'color' => $colored ? self::cssColor($option->settings->color) : null];
        }

        return array_values($tags);
    }

    /** @return list<array{id: string, label: string, color: ?string}> */
    public static function offeredTagOptions(FilterField $field): array
    {
        $tags = [];

        foreach ($field->options as $option) {
            $tags[mb_strtolower($option['id'])] = $option;
        }

        if ($field->definition instanceof CustomField) {
            foreach (resolve(StoredTagsQuery::class)->forField($field->definition, self::MAX_TAG_OPTIONS) as $name) {
                $tags[mb_strtolower($name)] ??= ['id' => $name, 'label' => $name, 'color' => null];
            }
        }

        return array_slice(array_values($tags), 0, self::MAX_TAG_OPTIONS);
    }

    private static function showsOptionColors(CustomField $field): bool
    {
        return FeatureManager::isEnabled(CustomFieldsFeature::FIELD_OPTION_COLORS) && $field->settings->enable_option_colors;
    }

    private static function cssColor(mixed $color): ?string
    {
        if (! is_string($color) || $color === '') {
            return null;
        }

        if (preg_match('/^#[0-9a-f]{3,8}$/i', $color) === 1) {
            return $color;
        }

        $palette = FilamentColor::getColors()[$color] ?? Color::all()[$color] ?? null;

        return is_array($palette) && is_string($palette[500] ?? null) ? $palette[500] : null;
    }
}
