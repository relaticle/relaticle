<?php

declare(strict_types=1);

namespace App\Actions\Note;

use App\Models\Note;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

final readonly class ListNotes
{
    /** @return CursorPaginator<int, Note>|LengthAwarePaginator<int, Note> */
    public function execute(User $user, ?int $perPage = null): CursorPaginator|LengthAwarePaginator
    {
        abort_unless($user->can('viewAny', Note::class), 403);

        $perPage = max(1, min($perPage ?? (int) (request()->query('per_page', '15')), 100));

        $query = QueryBuilder::for(Note::query()->withCustomFieldValues())
            ->allowedFilters([
                AllowedFilter::partial('title'),
            ])
            ->allowedFields(['id', 'title', 'creator_id', 'created_at', 'updated_at'])
            ->allowedIncludes(['creator', 'companies', 'people', 'opportunities'])
            ->allowedSorts(['title', 'created_at', 'updated_at'])
            ->defaultSort('-created_at');

        if (request()->has('cursor')) {
            return $query->cursorPaginate($perPage);
        }

        return $query->paginate($perPage);
    }
}
