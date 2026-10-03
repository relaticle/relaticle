<?php

declare(strict_types=1);

namespace App\Actions\Task;

use App\Enums\CrmEntity;
use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\Task;
use App\Models\User;
use App\Support\Filters\EntityFilters;
use App\Support\Filters\FilterTree;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
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
        FilterTree::validate($request->input('filter'), CrmEntity::Task);
        $filterSchema = new CustomFieldFilterSchema;

        $query = QueryBuilder::for(
            Task::query()->withCustomFieldValues()->whereBelongsTo($user->currentWorkspace),
            $request,
        )
            ->allowedFilters(...new EntityFilters($user)->for(CrmEntity::Task))
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
