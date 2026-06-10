<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Filament\Resources\TaskResource;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Data\MyTaskItem;

final readonly class MyTasksService
{
    private const int MAX_ITEMS = 5;

    /**
     * @return Collection<int, MyTaskItem>
     */
    public function forUser(User $user, Team $team): Collection
    {
        $startOfToday = Date::now()->startOfDay();
        $startOfDayAfter = $startOfToday->copy()->addDay();

        $meta = $this->resolveFieldMetadata($team);
        $dueFieldId = $meta->dueFieldId;

        $query = DB::table('tasks as t')
            ->join('task_user as tu', 'tu.task_id', '=', 't.id')
            ->where('t.team_id', $team->getKey())
            ->where('tu.user_id', $user->getKey())
            ->whereNull('t.deleted_at')
            ->when($meta->doneOptionId !== null, function (Builder $query) use ($meta): void {
                $query->whereNotExists(function (Builder $sub) use ($meta): void {
                    $sub->select(DB::raw(1))
                        ->from('custom_field_values as st')
                        ->whereColumn('st.entity_id', 't.id')
                        ->where('st.entity_type', 'task')
                        ->where('st.custom_field_id', $meta->statusFieldId)
                        ->where('st.string_value', $meta->doneOptionId);
                });
            });

        if ($dueFieldId !== null) {
            $query
                ->select(['t.id', 't.title', 't.created_at', 'due.datetime_value as due_at'])
                ->leftJoin('custom_field_values as due', function (JoinClause $join) use ($dueFieldId): void {
                    $join->on('due.entity_id', '=', 't.id')
                        ->where('due.entity_type', '=', 'task')
                        ->where('due.custom_field_id', '=', $dueFieldId);
                })
                ->orderByRaw('due.datetime_value ASC NULLS LAST');
        } else {
            $query
                ->select(['t.id', 't.title', 't.created_at'])
                ->selectRaw('NULL as due_at');
        }

        $rows = $query
            ->latest('t.created_at')
            ->limit(self::MAX_ITEMS)
            ->get();

        $tasksIndexUrl = TaskResource::getUrl('index', ['tenant' => $team]);

        return $rows->map(function (object $row) use ($tasksIndexUrl, $startOfToday, $startOfDayAfter): MyTaskItem {
            $dueAt = $row->due_at !== null ? Date::parse($row->due_at) : null;

            $editUrl = $tasksIndexUrl.'?'.http_build_query([
                'tableAction' => 'edit',
                'tableActionRecord' => (string) $row->id,
            ]);

            return new MyTaskItem(
                id: (string) $row->id,
                title: (string) $row->title,
                dueAt: $dueAt,
                severity: $dueAt instanceof Carbon ? $this->severity($dueAt, $startOfToday, $startOfDayAfter) : null,
                editUrl: $editUrl,
            );
        })->values();
    }

    /**
     * Resolves the per-tenant custom-field IDs the main query needs.
     *
     * One round-trip pulls both the `due_date` and `status` field IDs plus the
     * `Done` option ID (joined to `custom_field_options`). Memoized on the
     * application container so concurrent dashboard renders within the same
     * request reuse the result instead of refiring three lookups each time.
     */
    private function resolveFieldMetadata(Team $team): MyTasksFieldMetadata
    {
        $cacheKey = MyTasksFieldMetadata::class.':'.$team->getKey();

        if (app()->bound($cacheKey)) {
            /** @var MyTasksFieldMetadata $cached */
            $cached = resolve($cacheKey);

            return $cached;
        }

        $row = DB::table('custom_fields as cf')
            ->leftJoin('custom_field_options as opt', function (JoinClause $join): void {
                $join->on('opt.custom_field_id', '=', 'cf.id')
                    ->where('opt.name', '=', 'Done');
            })
            ->where('cf.tenant_id', $team->getKey())
            ->where('cf.entity_type', 'task')
            ->whereIn('cf.code', ['due_date', 'status'])
            ->selectRaw(implode(', ', [
                "MAX(CASE WHEN cf.code = 'due_date' THEN cf.id END) AS due_field_id",
                "MAX(CASE WHEN cf.code = 'status' THEN cf.id END) AS status_field_id",
                "MAX(CASE WHEN cf.code = 'status' THEN opt.id END) AS done_option_id",
            ]))
            ->first();

        $meta = new MyTasksFieldMetadata(
            dueFieldId: $row?->due_field_id !== null ? (string) $row->due_field_id : null,
            statusFieldId: $row?->status_field_id !== null ? (string) $row->status_field_id : null,
            doneOptionId: $row?->done_option_id !== null ? (string) $row->done_option_id : null,
        );

        app()->instance($cacheKey, $meta);

        return $meta;
    }

    private function severity(Carbon $dueAt, Carbon $startOfToday, Carbon $startOfDayAfter): string
    {
        if ($dueAt->lt($startOfToday)) {
            return 'overdue';
        }

        if ($dueAt->lt($startOfDayAfter)) {
            return 'today';
        }

        return 'tomorrow';
    }
}

/**
 * @internal Memoization holder for resolved custom-field IDs per team.
 */
final readonly class MyTasksFieldMetadata
{
    public function __construct(
        public ?string $dueFieldId,
        public ?string $statusFieldId,
        public ?string $doneOptionId,
    ) {}
}
