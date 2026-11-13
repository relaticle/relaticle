<?php

declare(strict_types=1);

namespace App\Filament\Tables\FilterBar;

use App\Enums\CrmEntity;
use App\Enums\FilterCondition;
use App\Enums\FilterFieldKind;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Queries\Filters\TreeFilter;
use App\Queries\FilterTree;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class FilterRules
{
    public const int MAX_EXAMINED_RULES = 50;

    private const array DELETED_MODES = ['included' => 1, 'only' => 0];

    /**
     * @param  array<string, FilterField>  $catalog
     * @return array{match: string, rules: list<array<string, mixed>>}
     */
    public static function heal(mixed $state, array $catalog, User $user): array
    {
        $entity = array_first($catalog)?->entity;
        $rules = is_array($state) && is_array($state['rules'] ?? null) ? array_slice(array_values($state['rules']), 0, self::MAX_EXAMINED_RULES) : [];
        $shaped = array_values(array_filter(array_map(fn (mixed $rule): ?array => self::shaped($rule, $catalog), $rules)));

        if (! $entity instanceof CrmEntity || $shaped === []) {
            return ['match' => 'all', 'rules' => []];
        }

        return ['match' => 'all', 'rules' => self::judged($shaped, $catalog, $user, $entity)];
    }

    /**
     * @param  array<array-key, mixed>  $tableFilters
     * @param  array<string, FilterField>  $catalog
     * @return array{conditions: array{match: string, rules: list<mixed>}, trashed: array{value: mixed}}
     */
    public static function fromLegacy(array $tableFilters, array $catalog): array
    {
        $conditions = $tableFilters[ConditionsFilter::NAME] ?? null;
        $rules = is_array($conditions) && is_array($conditions['rules'] ?? null) ? array_values($conditions['rules']) : [];

        foreach ($tableFilters as $key => $state) {
            array_push($rules, ...self::legacyRules((string) $key, $state, $catalog));
        }

        $trashed = $tableFilters['trashed'] ?? null;

        return ['conditions' => ['match' => 'all', 'rules' => $rules], 'trashed' => ['value' => self::deleted(is_array($trashed) ? ($trashed['value'] ?? null) : null)]];
    }

    public static function deletedValue(?string $mode): ?int
    {
        return $mode === null ? null : (self::DELETED_MODES[$mode] ?? null);
    }

    public static function deletedMode(mixed $value): ?string
    {
        $mode = array_search(self::deleted($value), self::DELETED_MODES, true);

        return $mode === false ? null : (string) $mode;
    }

    private static function deleted(mixed $value): ?int
    {
        return match (true) {
            $value === true, $value === 1, $value === '1', $value === 'true' => self::DELETED_MODES['included'],
            $value === false, $value === 0, $value === '0', $value === 'false' => self::DELETED_MODES['only'],
            default => null,
        };
    }

    /**
     * @param  array<string, FilterField>  $catalog
     * @return list<array<string, mixed>>
     */
    private static function legacyRules(string $key, mixed $state, array $catalog): array
    {
        if ($key === 'assigned_to_me') {
            $active = is_array($state) && filter_var($state['isActive'] ?? false, FILTER_VALIDATE_BOOLEAN);

            return $active ? [['field' => 'assignees', 'condition' => FilterCondition::IsAnyOf->value, 'value' => [FilterRuleCompiler::ME]]] : [];
        }

        if ($key === 'custom_fields' && is_array($state)) {
            $rules = [];

            foreach ($state as $code => $codeState) {
                array_push($rules, ...self::legacyRules(FilterFieldCatalog::CUSTOM_FIELD_PREFIX.$code, $codeState, $catalog));
            }

            return $rules;
        }

        $isLegacyKey = $key === 'creation_source' || $key === 'assignees' || str_starts_with($key, FilterFieldCatalog::CUSTOM_FIELD_PREFIX);
        $field = $isLegacyKey ? ($catalog[$key] ?? null) : null;
        $values = is_array($state) && is_array($state['values'] ?? null) ? array_values($state['values']) : [];
        $condition = $field instanceof FilterField ? $field->kind->legacyCondition() : null;

        return $values === [] || ! $condition instanceof FilterCondition
            ? []
            : [['field' => $key, 'condition' => $condition->value, 'value' => $values]];
    }

    /**
     * @param  array<string, FilterField>  $catalog
     * @return array<string, mixed>|null
     */
    private static function shaped(mixed $rule, array $catalog): ?array
    {
        $field = is_array($rule) && is_string($rule['field'] ?? null) ? ($catalog[$rule['field']] ?? null) : null;
        $condition = is_array($rule) && is_string($rule['condition'] ?? null) ? FilterCondition::tryFrom($rule['condition']) : null;

        if (! is_array($rule) || ! $field instanceof FilterField || ! $condition instanceof FilterCondition || ! in_array($condition, $field->conditions(), true)) {
            return null;
        }

        $canonical = ['field' => $field->key, 'condition' => $condition->value];

        if (! $condition->takesValue()) {
            return $canonical;
        }

        $value = FilterValueShape::of($condition, $field->kind, $rule['value'] ?? null);

        return $value === null ? null : [...$canonical, 'value' => $value];
    }

    /**
     * @param  list<array<string, mixed>>  $shaped
     * @param  array<string, FilterField>  $catalog
     * @return list<array<string, mixed>>
     */
    private static function judged(array $shaped, array $catalog, User $user, CrmEntity $entity): array
    {
        $known = [];
        $zone = FilamentTimezone::get();
        $compiler = new FilterRuleCompiler;
        $tree = new TreeFilter($entity, $user, $zone);
        $kept = [];
        $nodes = [];

        foreach ($shaped as $rule) {
            $field = $catalog[$rule['field']];
            $rule = self::resolved($rule, $field, $known, $shaped, $user);
            $compiled = $rule === null ? [] : $compiler->compile([$rule], $catalog, $user, $zone);

            if ($rule === null || $compiled === [] || ! self::applies($tree, $entity, $compiled)) {
                continue;
            }

            if (! self::fits([...$nodes, $compiled['$and'][0]], $entity)) {
                break;
            }

            $nodes[] = $compiled['$and'][0];
            $kept[] = $rule;
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, list<string>>  $known
     * @param  list<array<string, mixed>>  $shaped
     * @return array<string, mixed>|null
     */
    private static function resolved(array $rule, FilterField $field, array &$known, array $shaped, User $user): ?array
    {
        $value = $rule['value'] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            return $rule;
        }

        $lookup = match (true) {
            $field->kind->holdsRecordIds() => 'records',
            $field->picksFromOptions() => 'options',
            default => null,
        };

        if ($lookup === null) {
            return $rule;
        }

        $known[$field->key] ??= $lookup === 'options'
            ? array_column($field->options, 'id')
            : self::existingIds($field, $shaped, $user);

        $kept = array_values(array_filter(
            $value,
            fn (string $id): bool => ($field->kind === FilterFieldKind::Member && $id === FilterRuleCompiler::ME) || in_array($id, $known[$field->key], true),
        ));

        return $kept === [] ? null : [...$rule, 'value' => $kept];
    }

    /**
     * @param  list<array<string, mixed>>  $shaped
     * @return list<string>
     */
    private static function existingIds(FilterField $field, array $shaped, User $user): array
    {
        $wanted = [];

        foreach ($shaped as $rule) {
            if ($rule['field'] === $field->key && is_array($rule['value'] ?? null)) {
                array_push($wanted, ...array_filter($rule['value'], Str::isUlid(...)));
            }
        }

        $wanted = array_values(array_unique($wanted));
        $workspace = $user->currentWorkspace;

        if ($wanted === []) {
            return [];
        }

        if ($field->kind === FilterFieldKind::Member) {
            return array_values(array_map(strval(...), User::query()->memberOf($workspace)->whereKey($wanted)->pluck('users.id')->all()));
        }

        if (! $field->related instanceof CrmEntity) {
            return [];
        }

        $ids = $field->related->model()::query()
            ->withoutGlobalScopes([WorkspaceScope::class, SoftDeletingScope::class])
            ->whereBelongsTo($workspace)
            ->whereKey($wanted)
            ->pluck((new ($field->related->model()))->getKeyName())
            ->all();

        return array_values(array_map(strval(...), $ids));
    }

    /** @param  array<string, list<array<string, mixed>>>  $compiled */
    private static function applies(TreeFilter $tree, CrmEntity $entity, array $compiled): bool
    {
        try {
            $tree($entity->model()::query(), $compiled, ConditionsFilter::NAME);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /** @param  list<array<string, mixed>>  $nodes */
    private static function fits(array $nodes, CrmEntity $entity): bool
    {
        try {
            FilterTree::validate(['$and' => $nodes], $entity);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }
}
