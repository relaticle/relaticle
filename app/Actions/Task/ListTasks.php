<?php

declare(strict_types=1);

namespace App\Actions\Task;

use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

final readonly class ListTasks
{
    /** @return CursorPaginator<int, Task>|LengthAwarePaginator<int, Task> */
    public function execute(User $user, ?int $perPage = null): CursorPaginator|LengthAwarePaginator
    {
        abort_unless($user->can('viewAny', Task::class), 403);

        $perPage = max(1, min($perPage ?? (int) (request()->query('per_page', '15')), 100));

        $query = QueryBuilder::for(Task::query()->withCustomFieldValues())
            ->allowedFilters([
                AllowedFilter::partial('title'),
                AllowedFilter::callback('assigned_to_me', function (Builder $query) use ($user): void {
                    $query->whereHas('assignees', fn (Builder $q) => $q->where('users.id', $user->getKey()));
                }),
            ])
            ->allowedFields(['id', 'title', 'creator_id', 'created_at', 'updated_at'])
            ->allowedIncludes(['creator', 'assignees', 'companies', 'people', 'opportunities'])
            ->allowedSorts(['title', 'created_at', 'updated_at'])
            ->defaultSort('-created_at');

        if (request()->has('cursor')) {
            return $query->cursorPaginate($perPage);
        }

        return $query->paginate($perPage);
    }
}
