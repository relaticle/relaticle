<?php

declare(strict_types=1);

namespace App\Mcp\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\AllowedFilter;

final class CustomFieldAllowedFilter extends AllowedFilter
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function applyTo(Builder $builder, mixed $value): void
    {
        // Spatie prunes empty arrays first, so `not_in: []` would become no filter at all.
        ($this->filterClass)($builder, $value, $this->internalName);
    }
}
