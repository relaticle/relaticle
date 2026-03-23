<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Mcp\Filters\CustomFieldFilter;
use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\QueryBuilder;

final readonly class ListCompanies
{
    /**
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, Company>|LengthAwarePaginator<int, Company>
     */
    public function execute(
        User $user,
        int $perPage = 15,
        bool $useCursor = false,
        array $filters = [],
        ?int $page = null,
        ?Request $request = null,
    ): CursorPaginator|LengthAwarePaginator {
        abort_unless($user->can('viewAny', Company::class), 403);

        $perPage = max(1, min($perPage, 100));

        $request ??= new Request(['filter' => $filters]);
        $filterSchema = new CustomFieldFilterSchema;

        $query = QueryBuilder::for(Company::query()->withCustomFieldValues(), $request)
            ->allowedFilters(
                AllowedFilter::partial('name'),
                AllowedFilter::custom('custom_fields', new CustomFieldFilter('company')),
            )
            ->allowedFields('id', 'name', 'creator_id', 'account_owner_id', 'created_at', 'updated_at')
            ->allowedIncludes(
                'creator', 'people', 'opportunities',
                AllowedInclude::count('peopleCount', 'people'),
                AllowedInclude::count('opportunitiesCount', 'opportunities'),
                AllowedInclude::count('tasksCount', 'tasks'),
                AllowedInclude::count('notesCount', 'notes'),
            )
            ->allowedSorts(
                'name', 'created_at', 'updated_at',
                ...$filterSchema->allowedSorts($user, 'company'),
            )
            ->defaultSort('-created_at');

        if ($useCursor) {
            return $query->cursorPaginate($perPage);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }
}
