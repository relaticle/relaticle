<?php

declare(strict_types=1);

namespace App\Actions\Task;

use App\Mcp\Filters\CustomFieldFilter;
use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\QueryBuilder;

final readonly class ListTasks
{
    /**
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, Task>|LengthAwarePaginator<int, Task>
     */
    public function execute(
        User $user,
        int $perPage = 15,
        bool $useCursor = false,
        array $filters = [],
        ?int $page = null,
        ?Request $request = null,
    ): CursorPaginator|LengthAwarePaginator {
        abort_unless($user->can('viewAny', Task::class), 403);

        $request ??= new Request(['filter' => $filters]);
        $filterSchema = new CustomFieldFilterSchema;

        $query = QueryBuilder::for(Task::query()->withCustomFieldValues(), $request)
            ->allowedFilters(
                AllowedFilter::partial('title'),
                AllowedFilter::callback('assigned_to_me', function (Builder $query, mixed $value) use ($user): void {
                    if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                        $query->whereHas('assignees', fn (Builder $q) => $q->where('users.id', $user->getKey()));
                    }
                }),
                AllowedFilter::scope('company_id', 'forCompany'),
                AllowedFilter::scope('people_id', 'forPerson'),
                AllowedFilter::scope('opportunity_id', 'forOpportunity'),
                AllowedFilter::custom('custom_fields', new CustomFieldFilter('task')),
            )
            ->allowedFields('id', 'title', 'creator_id', 'created_at', 'updated_at')
            ->allowedIncludes(
                'creator', 'assignees', 'companies', 'people', 'opportunities',
                AllowedInclude::count('assigneesCount', 'assignees'),
                AllowedInclude::count('companiesCount', 'companies'),
                AllowedInclude::count('peopleCount', 'people'),
                AllowedInclude::count('opportunitiesCount', 'opportunities'),
            )
            ->allowedSorts(
                'title', 'created_at', 'updated_at',
                ...$filterSchema->allowedSorts($user, 'task'),
            )
            ->defaultSort('-created_at')
            ->orderBy('id');

        if ($useCursor) {
            return $query->cursorPaginate($perPage);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }
}
