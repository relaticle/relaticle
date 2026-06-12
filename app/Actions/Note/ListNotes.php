<?php

declare(strict_types=1);

namespace App\Actions\Note;

use App\Mcp\Filters\CustomFieldFilter;
use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\Note;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\QueryBuilder;

final readonly class ListNotes
{
    /**
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, Note>|LengthAwarePaginator<int, Note>
     */
    public function execute(
        User $user,
        int $perPage = 15,
        bool $useCursor = false,
        array $filters = [],
        ?int $page = null,
        ?Request $request = null,
    ): CursorPaginator|LengthAwarePaginator {
        abort_unless($user->can('viewAny', Note::class), 403);

        $request ??= new Request(['filter' => $filters]);
        $filterSchema = new CustomFieldFilterSchema;

        $query = QueryBuilder::for(
            Note::query()->withCustomFieldValues()->whereBelongsTo($user->currentTeam),
            $request,
        )
            ->allowedFilters(
                AllowedFilter::partial('title'),
                AllowedFilter::scope('notable_type', 'forNotableType'),
                AllowedFilter::scope('notable_id', 'forNotableId'),
                AllowedFilter::custom('custom_fields', new CustomFieldFilter('note')),
            )
            ->allowedFields('id', 'title', 'creator_id', 'created_at', 'updated_at')
            ->allowedIncludes(
                'creator', 'companies', 'people', 'opportunities',
                AllowedInclude::count('companiesCount', 'companies'),
                AllowedInclude::count('peopleCount', 'people'),
                AllowedInclude::count('opportunitiesCount', 'opportunities'),
            )
            ->allowedSorts(
                'title', 'created_at', 'updated_at',
                ...$filterSchema->allowedSorts($user, 'note'),
            )
            ->defaultSort('-created_at')
            ->orderBy('id');

        if ($useCursor) {
            return $query->cursorPaginate($perPage);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }
}
