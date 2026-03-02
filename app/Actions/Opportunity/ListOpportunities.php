<?php

declare(strict_types=1);

namespace App\Actions\Opportunity;

use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

final readonly class ListOpportunities
{
    /** @return CursorPaginator<int, Opportunity>|LengthAwarePaginator<int, Opportunity> */
    public function execute(User $user, ?int $perPage = null): CursorPaginator|LengthAwarePaginator
    {
        abort_unless($user->can('viewAny', Opportunity::class), 403);

        $perPage = max(1, min($perPage ?? (int) (request()->query('per_page', '15')), 100));

        $query = QueryBuilder::for(Opportunity::query()->withCustomFieldValues())
            ->allowedFilters([
                AllowedFilter::partial('name'),
                AllowedFilter::exact('company_id'),
            ])
            ->allowedFields(['id', 'name', 'company_id', 'contact_id', 'creator_id', 'created_at', 'updated_at'])
            ->allowedIncludes(['creator', 'company', 'contact'])
            ->allowedSorts(['name', 'created_at', 'updated_at'])
            ->defaultSort('-created_at');

        if (request()->has('cursor')) {
            return $query->cursorPaginate($perPage);
        }

        return $query->paginate($perPage);
    }
}
