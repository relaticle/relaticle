<?php

declare(strict_types=1);

namespace App\Queries\Filters;

use App\Enums\CrmEntity;
use App\Models\User;
use App\Queries\Concerns\AppliesFilterNodes;
use App\Queries\EntityFilters;
use App\Queries\FilterTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * @implements Filter<Model>
 */
final readonly class TreeFilter implements Filter
{
    use AppliesFilterNodes;

    public function __construct(
        private CrmEntity $entity,
        private User $user,
        private ?string $viewerZone,
    ) {}

    /**
     * @param  Builder<Model>  $query
     */
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        FilterTree::validate($value, $this->entity);

        if (! is_array($value) || $value === []) {
            return;
        }

        $this->applyNode($query, new EntityFilters($this->user, $this->viewerZone)->for($this->entity), $value);
    }
}
