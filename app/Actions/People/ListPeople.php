<?php

declare(strict_types=1);

namespace App\Actions\People;

use App\Mcp\Filters\CustomFieldFilter;
use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\People;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\QueryBuilder;

final readonly class ListPeople
{
    /**
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, People>|LengthAwarePaginator<int, People>
     */
    public function execute(
        User $user,
        int $perPage = 15,
        bool $useCursor = false,
        array $filters = [],
        ?int $page = null,
        ?Request $request = null,
    ): CursorPaginator|LengthAwarePaginator {
        abort_unless($user->can('viewAny', People::class), 403);

        $perPage = max(1, min($perPage, 100));

        $request ??= new Request(['filter' => $filters]);
        $filterSchema = new CustomFieldFilterSchema;

        $query = QueryBuilder::for(People::query()->withCustomFieldValues(), $request)
            ->allowedFilters(
                AllowedFilter::partial('name'),
                AllowedFilter::exact('company_id'),
                AllowedFilter::custom('custom_fields', new CustomFieldFilter('people')),
            )
            ->allowedFields('id', 'name', 'company_id', 'creator_id', 'created_at', 'updated_at')
            ->allowedIncludes(
                'creator', 'company',
                AllowedInclude::count('tasksCount', 'tasks'),
                AllowedInclude::count('notesCount', 'notes'),
            )
            ->allowedSorts(
                'name', 'created_at', 'updated_at',
                ...$filterSchema->allowedSorts($user, 'people'),
            )
            ->defaultSort('-created_at');

        if ($useCursor) {
            return $query->cursorPaginate($perPage);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }
}
