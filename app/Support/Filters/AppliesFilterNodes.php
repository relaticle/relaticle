<?php

declare(strict_types=1);

namespace App\Support\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\AllowedFilter;

trait AppliesFilterNodes
{
    /**
     * @param  Builder<Model>  $query
     * @param  list<AllowedFilter>  $registry
     * @param  array<array-key, mixed>  $node
     */
    protected function applyNode(Builder $query, array $registry, array $node): void
    {
        if ($node === [] || array_is_list($node)) {
            throw FilterErrors::at('', __('validation.filter.node_object'));
        }

        foreach ($node as $name => $value) {
            $filter = array_find($registry, static fn (AllowedFilter $candidate): bool => $candidate->getName() === (string) $name);

            if (! $filter instanceof AllowedFilter) {
                throw FilterErrors::at((string) $name, __('validation.filter.unknown_name', [
                    'name' => $name,
                    'available' => implode(', ', array_map(static fn (AllowedFilter $candidate): string => $candidate->getName(), $registry)),
                ]));
            }

            $filter->applyTo($query, $value);
        }
    }
}
