<?php

declare(strict_types=1);

namespace App\Filament\Tables\FilterBar;

use App\Data\LinkOption;
use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Enums\FilterCondition;
use App\Enums\FilterFieldKind;
use App\Enums\PeriodUnit;
use App\Models\CustomField;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Number;
use Throwable;

final readonly class FilterRuleDisplay
{
    private const int SHOWN_VALUES = 2;

    private const int MAX_DECIMALS = 10;

    /**
     * @param  list<array<string, mixed>>  $rules
     * @param  array<string, FilterField>  $catalog
     * @return list<array{display: string, labels: list<array{id: string, label: string, image: ?string, icon: ?string, circular: bool}>}>
     */
    public static function describe(array $rules, array $catalog, User $user): array
    {
        $names = self::names($rules, $catalog, $user);

        return array_map(function (array $rule) use ($catalog, $names, $user): array {
            $field = $catalog[$rule['field']];
            $known = $names[$field->key] ?? [];
            $rule['value'] = self::withViewerOnce($rule['value'] ?? null, $field, $user);
            $ids = $field->kind->holdsRecordIds() && is_array($rule['value']) ? $rule['value'] : [];

            return [
                'display' => self::one($rule, $field, $known),
                'labels' => array_values(array_map(
                    fn (mixed $id): array => ($known[(string) $id] ?? new LinkOption((string) $id, self::label($field, (string) $id, $known)))->toArray(),
                    $ids,
                )),
            ];
        }, $rules);
    }

    private static function withViewerOnce(mixed $value, FilterField $field, User $user): mixed
    {
        if ($field->kind !== FilterFieldKind::Member || ! is_array($value) || ! in_array(FilterRuleCompiler::ME, $value, true)) {
            return $value;
        }

        return array_values(array_filter($value, fn (mixed $id): bool => (string) $id !== (string) $user->getKey()));
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, LinkOption>  $names
     */
    private static function one(array $rule, FilterField $field, array $names): string
    {
        $value = $rule['value'] ?? null;

        if (is_array($value) && array_is_list($value)) {
            return self::summary(array_map(fn (mixed $id): string => self::label($field, (string) $id, $names), $value));
        }

        if (is_array($value)) {
            return self::period($value, $rule['condition'] ?? null);
        }

        if ($field->kind === FilterFieldKind::Inactivity && is_numeric($value)) {
            return trans_choice('filament/panel.tables.filters.periods.days', (int) $value);
        }

        if ($field->kind === FilterFieldKind::Date && is_string($value)) {
            return self::date($value);
        }

        if ($field->kind === FilterFieldKind::Number && is_numeric($value)) {
            return self::number((float) $value, $field);
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private static function number(float $value, FilterField $field): string
    {
        $definition = $field->definition;

        if ($field->type !== CustomFieldType::CURRENCY || ! $definition instanceof CustomField) {
            return (string) Number::format($value, maxPrecision: self::MAX_DECIMALS);
        }

        $decimals = $definition->getDecimalPlaces();

        return $definition->getCurrencyDisplayType() === 'code'
            ? $definition->getCurrencyCode().' '.Number::format($value, $decimals)
            : (string) Number::currency($value, $definition->getCurrencyCode(), precision: $decimals);
    }

    /** @param  array<string, LinkOption>  $names */
    private static function label(FilterField $field, string $id, array $names): string
    {
        if ($field->kind->holdsRecordIds()) {
            return isset($names[$id]) ? $names[$id]->label : $id;
        }

        foreach ($field->options as $option) {
            if ($option['id'] === $id) {
                return $option['label'];
            }
        }

        return $id;
    }

    /** @param  list<string>  $labels */
    private static function summary(array $labels): string
    {
        $shown = implode(', ', array_slice($labels, 0, self::SHOWN_VALUES));
        $more = count($labels) - self::SHOWN_VALUES;

        return $more > 0 ? "{$shown} +{$more}" : $shown;
    }

    /** @param  array<array-key, mixed>  $value */
    private static function period(array $value, mixed $condition): string
    {
        $unit = is_string($value['unit'] ?? null) ? PeriodUnit::tryFrom($value['unit']) : null;

        if (! $unit instanceof PeriodUnit) {
            return '';
        }

        return $condition === FilterCondition::This->value
            ? __("filament/panel.tables.filters.this_unit.{$unit->value}")
            : trans_choice("filament/panel.tables.filters.periods.{$unit->value}", (int) ($value['amount'] ?? 0));
    }

    private static function date(string $value): string
    {
        if ($value === '@today') {
            return __('filament/panel.tables.filters.today');
        }

        try {
            return Date::parse($value)->isoFormat('ll');
        } catch (Throwable) {
            return $value;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @param  array<string, FilterField>  $catalog
     * @return array<string, array<string, LinkOption>>
     */
    private static function names(array $rules, array $catalog, User $user): array
    {
        $wanted = [];

        foreach ($rules as $rule) {
            $field = $catalog[$rule['field']];

            if ($field->kind->holdsRecordIds() && is_array($rule['value'] ?? null)) {
                $ids = array_filter($rule['value'], fn (mixed $id): bool => $id !== FilterRuleCompiler::ME);
                $wanted[$field->key] = [...($wanted[$field->key] ?? []), ...$ids];
            }
        }

        $names = [];

        foreach ($wanted as $key => $ids) {
            $names[$key] = self::namesFor($catalog[$key], array_values(array_unique($ids)), $user);
        }

        foreach ($rules as $rule) {
            $field = $catalog[$rule['field']];

            if ($field->kind !== FilterFieldKind::Member || ! is_array($rule['value'] ?? null)) {
                continue;
            }

            foreach ([FilterRuleCompiler::ME, (string) $user->getKey()] as $id) {
                if (in_array($id, array_map(strval(...), $rule['value']), true)) {
                    $names[$field->key][$id] = self::viewer($user, $id);
                }
            }
        }

        return $names;
    }

    public static function viewer(User $user, string $id = FilterRuleCompiler::ME): LinkOption
    {
        return new LinkOption(
            id: $id,
            label: __('filament/panel.tables.filters.me_named', ['name' => $user->name]),
            image: $user->getFilamentAvatarUrl(),
        );
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, LinkOption>
     */
    private static function namesFor(FilterField $field, array $ids, User $user): array
    {
        $workspace = $user->currentWorkspace;

        if ($field->kind === FilterFieldKind::Member) {
            return User::query()->memberOf($workspace)->whereKey($ids)->select(LinkOption::columns(new User, 'name'))->get()
                ->mapWithKeys(fn (User $member): array => [(string) $member->getKey() => LinkOption::forRecord($member, $member->name)])
                ->all();
        }

        if (! $field->related instanceof CrmEntity) {
            return [];
        }

        $entity = $field->related;
        $model = $entity->model();
        $column = $field->related->titleColumn();
        $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($model), true);
        $names = [];

        $records = $model::query()
            ->withoutGlobalScopes([WorkspaceScope::class, SoftDeletingScope::class])
            ->whereBelongsTo($workspace)
            ->whereKey($ids)
            ->select(LinkOption::columns(new $model, $column))
            ->with(LinkOption::marks(new $model))
            ->get();

        foreach ($records as $record) {
            $name = (string) $record->getAttribute($column);
            $names[(string) $record->getKey()] = $softDeletes && $record->getAttribute('deleted_at') !== null
                ? new LinkOption((string) $record->getKey(), __('filament/panel.tables.filters.deleted_record', ['name' => $name]), null, $entity->iconPath(), $entity !== CrmEntity::Company)
                : LinkOption::forRecord($record, $name, $entity);
        }

        return $names;
    }
}
