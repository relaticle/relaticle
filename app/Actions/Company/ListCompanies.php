<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

final readonly class ListCompanies
{
    /** @return CursorPaginator<int, Company>|LengthAwarePaginator<int, Company> */
    public function execute(User $user, ?int $perPage = null): CursorPaginator|LengthAwarePaginator
    {
        abort_unless($user->can('viewAny', Company::class), 403);

        $perPage = max(1, min($perPage ?? (int) (request()->query('per_page', '15')), 100));

        $query = QueryBuilder::for(Company::query()->withCustomFieldValues())
            ->allowedFilters([
                AllowedFilter::partial('name'),
            ])
            ->allowedFields(['id', 'name', 'creator_id', 'account_owner_id', 'created_at', 'updated_at'])
            ->allowedIncludes(['creator', 'people', 'opportunities'])
            ->allowedSorts(['name', 'created_at', 'updated_at'])
            ->defaultSort('-created_at');

        if (request()->has('cursor')) {
            return $query->cursorPaginate($perPage);
        }

        return $query->paginate($perPage);
    }
}
