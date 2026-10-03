<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Enums\CrmEntity;
use App\Enums\FilterKind;

final readonly class FilterTree
{
    public const int MAX_CONDITIONS = 20;

    public const int MAX_LOGIC_DEPTH = 3;

    public const int MAX_HOPS = 2;

    private const array REPLACED = [
        'search' => 'name or title with $contains',
        'created_after' => 'created_at with $gte',
        'created_before' => 'created_at with $lte',
        'company_id' => 'company (or companies) with $in',
        'contact_id' => 'contact with $in',
        'people_id' => 'people with $in',
        'opportunity_id' => 'opportunities with $in',
        'assignee_ids' => 'assignees with $in',
        'notable_type' => 'companies, people or opportunities with $in',
        'notable_id' => 'companies, people or opportunities with $in',
    ];

    public static function validate(mixed $filter, CrmEntity $entity): void
    {
        if (in_array($filter, [null, '', []], true)) {
            return;
        }

        if (! is_array($filter) || array_is_list($filter)) {
            throw FilterErrors::at('filter', __('validation.filter.not_object'));
        }

        $conditions = self::walk($filter, $entity, 'filter', 0, 0);

        if ($conditions > self::MAX_CONDITIONS) {
            throw FilterErrors::at('filter', __('validation.filter.too_many_conditions', ['max' => self::MAX_CONDITIONS, 'count' => $conditions]));
        }
    }

    /**
     * @param  array<array-key, mixed>  $node
     */
    private static function walk(array $node, CrmEntity $entity, string $path, int $depth, int $hops): int
    {
        if ($node === []) {
            throw FilterErrors::at($path, __('validation.filter.empty_node', ['name' => $path]));
        }

        $definitions = EntityFilters::definitions($entity);
        $conditions = 0;

        foreach ($node as $name => $value) {
            $name = (string) $name;
            $child = "{$path}.{$name}";

            if (in_array($name, LogicFilter::KEYWORDS, true)) {
                $conditions += self::walkLogic($name, $value, $entity, $child, $depth, $hops);

                continue;
            }

            if (isset(self::REPLACED[$name])) {
                throw FilterErrors::at($child, __('validation.filter.replaced', ['name' => $name, 'replacement' => self::REPLACED[$name]]));
            }

            if ($name === 'custom_fields') {
                $conditions += self::walkCustomFields($value, $child, $path === 'filter');

                continue;
            }

            $definition = $definitions[$name] ?? throw FilterErrors::at($child, __('validation.filter.unknown_name', [
                'name' => $name,
                'available' => implode(', ', [...array_keys($definitions), 'custom_fields', ...LogicFilter::KEYWORDS]),
            ]));

            if ($value === null || $value === '') {
                throw FilterErrors::at($child, __('validation.filter.operator_object', ['name' => $name, 'operator' => $definition->operators()[0]]));
            }

            if ($definition->kind !== FilterKind::Relation && $definition->kind !== FilterKind::Members) {
                $conditions += self::countOperators($value);

                continue;
            }

            $conditions += self::walkRelation($name, $value, $definition, $child, $depth, $hops);
        }

        return $conditions;
    }

    private static function walkLogic(string $keyword, mixed $value, CrmEntity $entity, string $path, int $depth, int $hops): int
    {
        if ($depth + 1 > self::MAX_LOGIC_DEPTH) {
            throw FilterErrors::at($path, __('validation.filter.too_deep', ['max' => self::MAX_LOGIC_DEPTH]));
        }

        $branches = $keyword === '$not' ? [$value] : (is_array($value) && array_is_list($value) && $value !== [] ? $value : null);

        if ($branches === null) {
            throw FilterErrors::at($path, __('validation.filter.logic_list', ['keyword' => $keyword]));
        }

        $conditions = 0;

        foreach ($branches as $index => $branch) {
            $conditions += self::walk(is_array($branch) ? $branch : [], $entity, $keyword === '$not' ? $path : "{$path}.{$index}", $depth + 1, $hops);
        }

        return $conditions;
    }

    private static function walkRelation(string $name, mixed $value, FilterDefinition $definition, string $path, int $depth, int $hops): int
    {
        if ($hops + 1 > self::MAX_HOPS) {
            throw FilterErrors::at($path, __('validation.filter.too_many_hops', ['max' => self::MAX_HOPS]));
        }

        if ($value === []) {
            throw FilterErrors::at($path, __('validation.filter.empty_node', ['name' => $path]));
        }

        if (! is_array($value) || array_is_list($value)) {
            throw FilterErrors::at($path, __('validation.filter.operator_object', ['name' => $name, 'operator' => '$in']));
        }

        $links = array_flip(FilterDefinition::LINK_OPERATORS);
        $conditions = count(array_intersect_key($value, $links));
        $nested = array_diff_key($value, $links);

        foreach (array_keys($nested) as $key) {
            $key = (string) $key;

            if (in_array('$'.$key, FilterDefinition::LINK_OPERATORS, true)) {
                throw FilterErrors::at("{$path}.{$key}", __('validation.filter.operator_sigil', ['operator' => '$'.$key]));
            }

            if ($definition->related instanceof CrmEntity && str_starts_with($key, '$') && ! in_array($key, LogicFilter::KEYWORDS, true)) {
                throw FilterErrors::at("{$path}.{$key}", __('validation.filter.relation_operator', ['name' => $name, 'operator' => $key]));
            }
        }

        if ($nested === [] || ! $definition->related instanceof CrmEntity) {
            return $conditions;
        }

        return $conditions + self::walk($nested, $definition->related, $path, $depth, $hops + 1);
    }

    private static function walkCustomFields(mixed $value, string $path, bool $topLevel): int
    {
        if (! $topLevel && ($value === null || $value === '')) {
            throw FilterErrors::at($path, __('validation.custom_field.filter_not_object'));
        }

        if (! $topLevel && $value === []) {
            throw FilterErrors::at($path, __('validation.filter.empty_node', ['name' => $path]));
        }

        if (is_array($value)) {
            $empty = array_find_key($value, static fn (mixed $operators): bool => $operators === []);

            if ($empty !== null) {
                throw FilterErrors::at("{$path}.{$empty}", __('validation.filter.empty_node', ['name' => "{$path}.{$empty}"]));
            }
        }

        return self::countOperators($value);
    }

    private static function countOperators(mixed $value): int
    {
        if (! is_array($value)) {
            return 1;
        }

        $count = 0;

        foreach ($value as $key => $child) {
            $count += str_starts_with((string) $key, '$') ? 1 : self::countOperators($child);
        }

        return $count;
    }
}
