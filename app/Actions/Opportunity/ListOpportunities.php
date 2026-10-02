<?php

declare(strict_types=1);

namespace App\Actions\Opportunity;

use App\Enums\CrmEntity;
use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\Filters\EntityFilters;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\QueryBuilder;

final readonly class ListOpportunities
{
    /**
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, Opportunity>|LengthAwarePaginator<int, Opportunity>
     */
    public function execute(
        User $user,
        int $perPage = 15,
        bool $useCursor = false,
        array $filters = [],
        ?int $page = null,
        ?Request $request = null,
    ): CursorPaginator|LengthAwarePaginator {
        abort_unless($user->can('viewAny', Opportunity::class), 403);

        $request ??= new Request(['filter' => $filters]);
        $filterSchema = new CustomFieldFilterSchema;

        $query = QueryBuilder::for(
            Opportunity::query()->withCustomFieldValues()->whereBelongsTo($user->currentWorkspace),
            $request,
        )
            ->allowedFilters(...new EntityFilters($user)->for(CrmEntity::Opportunity))
            ->allowedFields('id', 'name', 'company_id', 'contact_id', 'creator_id', 'created_at', 'updated_at')
            ->allowedIncludes(
                'creator', 'company', 'contact',
                AllowedInclude::count('tasksCount', 'tasks'),
                AllowedInclude::count('notesCount', 'notes'),
            )
            ->allowedSorts(
                'name', 'created_at', 'updated_at',
                ...$filterSchema->allowedSorts($user, 'opportunity'),
            )
            ->defaultSort('-created_at')
            ->orderBy('id');

        if ($useCursor) {
            return $query->cursorPaginate($perPage);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }
}
