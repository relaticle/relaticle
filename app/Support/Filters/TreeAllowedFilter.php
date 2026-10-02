<?php

declare(strict_types=1);

namespace App\Support\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

final class TreeAllowedFilter extends AllowedFilter
{
    /**
     * @param  QueryBuilder<*>  $query
     */
    public function filter(QueryBuilder $query, mixed $value): void
    {
        try {
            $this->applyTo($query->getEloquentBuilder(), $value);
        } catch (ValidationException $exception) {
            throw FilterErrors::prefix($exception, 'filter');
        }
    }

    /**
     * @param  Builder<Model>  $builder
     */
    public function applyTo(Builder $builder, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        try {
            ($this->filterClass)($builder, $value, $this->internalName);
        } catch (ValidationException $exception) {
            throw FilterErrors::prefix($exception, $this->getName());
        }
    }
}
