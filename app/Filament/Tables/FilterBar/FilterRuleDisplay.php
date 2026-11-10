<?php

declare(strict_types=1);

namespace App\Filament\Tables\FilterBar;

use App\Enums\CrmEntity;
use App\Enums\FilterFieldKind;
use App\Enums\PeriodUnit;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Date;
use Throwable;

final readonly class FilterRuleDisplay
{
    private const string ME = '@me';

    private const int SHOWN_VALUES = 2;

    /**
     * @param  list<array<string, mixed>>  $rules
     * @param  array<string, FilterField>  $catalog
     * @return list<string>
     */
    public static function for(array $rules, array $catalog, User $user): array
    {
        $names = self::names($rules, $catalog, $user);

        return array_map(
            fn (array $rule): string => self::one($rule, $catalog[$rule['field']], $names[$rule['field']] ?? []),
            $rules,
        );
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, string>  $names
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

        return is_scalar($value) ? (string) $value : '';
    }

    /** @param  array<string, string>  $names */
    private static function label(FilterField $field, string $id, array $names): string
    {
        if ($field->kind === FilterFieldKind::Member && $id === self::ME) {
            return __('filament/panel.tables.filters.me');
        }

        if ($field->kind->holdsRecordIds()) {
            return $names[$id] ?? $id;
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

        return $condition === 'this'
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
     * @return array<string, array<string, string>>
     */
    private static function names(array $rules, array $catalog, User $user): array
    {
        $wanted = [];

        foreach ($rules as $rule) {
            $field = $catalog[$rule['field']];

            if ($field->kind->holdsRecordIds() && is_array($rule['value'] ?? null)) {
                $ids = array_slice(array_filter($rule['value'], fn (mixed $id): bool => $id !== self::ME), 0, self::SHOWN_VALUES);
                $wanted[$field->key] = [...($wanted[$field->key] ?? []), ...$ids];
            }
        }

        $names = [];

        foreach ($wanted as $key => $ids) {
            $names[$key] = self::namesFor($catalog[$key], array_values(array_unique($ids)), $user);
        }

        return $names;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private static function namesFor(FilterField $field, array $ids, User $user): array
    {
        $workspace = $user->currentWorkspace;

        if ($field->kind === FilterFieldKind::Member) {
            return array_map(strval(...), User::query()->memberOf($workspace)->whereKey($ids)->pluck('users.name', 'users.id')->all());
        }

        if (! $field->related instanceof CrmEntity) {
            return [];
        }

        $model = $field->related->model();
        $column = $field->related->titleColumn();
        $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($model), true);
        $names = [];

        $records = $model::query()
            ->withoutGlobalScopes([WorkspaceScope::class, SoftDeletingScope::class])
            ->whereBelongsTo($workspace)
            ->whereKey($ids)
            ->get([(new $model)->getKeyName(), $column, ...($softDeletes ? ['deleted_at'] : [])]);

        foreach ($records as $record) {
            $name = (string) $record->getAttribute($column);
            $names[(string) $record->getKey()] = $softDeletes && $record->getAttribute('deleted_at') !== null
                ? __('filament/panel.tables.filters.deleted_record', ['name' => $name])
                : $name;
        }

        return $names;
    }
}
