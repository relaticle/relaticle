<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Contracts\Entitlements;
use App\Data\DigestRecord;
use App\Data\DigestTaskItem;
use App\Data\TaskDigest;
use App\Enums\CreationSource;
use App\Enums\CrmEntity;
use App\Enums\CustomFields\TaskField;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CanonicalRecordUrl;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class DigestService
{
    public function __construct(
        private Entitlements $entitlements,
        private CanonicalRecordUrl $urls,
    ) {}

    public function forWorkspace(User $user, Workspace $workspace): TaskDigest
    {
        if (! $this->entitlements->allowsAccess($workspace)) {
            return new TaskDigest([], []);
        }

        $meta = $this->resolveFieldMetadata($workspace);

        if ($meta['due_field_id'] === null) {
            return new TaskDigest([], []);
        }

        $startOfToday = Date::now($user->effectiveTimezone())->startOfDay()->utc();
        $rows = $this->dueTaskRows($user, $workspace, $meta, $startOfToday->addDay());

        if ($rows->isEmpty()) {
            return new TaskDigest([], []);
        }

        $records = $this->linkedRecords($workspace, array_values($rows->map(fn (object $row): string => (string) $row->id)->all()));
        $priorities = $this->priorityOptions($meta['priority_field_id'], $meta['priority_colors']);

        $overdue = [];
        $upcoming = [];

        foreach ($rows as $row) {
            $dueAt = Date::parse($row->due_at);
            $priority = $priorities[(string) $row->priority_option_id] ?? null;

            $item = new DigestTaskItem(
                title: (string) $row->title,
                dueAt: $dueAt,
                editUrl: $this->urls->buildOrFail(CrmEntity::Task, (string) $row->id, $workspace),
                records: $records[(string) $row->id] ?? [],
                priority: $priority?->name,
                priorityColor: $priority?->color,
            );

            if ($dueAt->lt($startOfToday)) {
                $overdue[] = $item;
            } else {
                $upcoming[] = $item;
            }
        }

        return new TaskDigest($overdue, $upcoming);
    }

    /**
     * @param  array{due_field_id: ?string, status_field_id: ?string, done_option_id: ?string, priority_field_id: ?string, priority_colors: bool}  $meta
     * @return Collection<int, stdClass>
     */
    private function dueTaskRows(User $user, Workspace $workspace, array $meta, CarbonImmutable $windowEnd): Collection
    {
        return DB::table('tasks as t')
            ->join('task_user as tu', 'tu.task_id', '=', 't.id')
            ->leftJoin('custom_field_values as due', function (JoinClause $join) use ($meta): void {
                $join->on('due.entity_id', '=', 't.id')
                    ->where('due.entity_type', '=', 'task')
                    ->where('due.custom_field_id', '=', $meta['due_field_id']);
            })
            ->leftJoin('custom_field_values as pr', function (JoinClause $join) use ($meta): void {
                $join->on('pr.entity_id', '=', 't.id')
                    ->where('pr.entity_type', '=', 'task')
                    ->where('pr.custom_field_id', '=', $meta['priority_field_id']);
            })
            ->where('t.workspace_id', $workspace->getKey())
            ->where('tu.user_id', $user->getKey())
            ->whereNull('t.deleted_at')
            ->where('t.creation_source', '!=', CreationSource::SAMPLE->value)
            ->whereNotNull('due.datetime_value')
            ->where('due.datetime_value', '<', $windowEnd)
            ->when($meta['done_option_id'] !== null, function (Builder $query) use ($meta): void {
                $query->whereNotExists(function (Builder $sub) use ($meta): void {
                    $sub->select(DB::raw(1))
                        ->from('custom_field_values as st')
                        ->whereColumn('st.entity_id', 't.id')
                        ->where('st.entity_type', 'task')
                        ->where('st.custom_field_id', $meta['status_field_id'])
                        ->where('st.string_value', $meta['done_option_id']);
                });
            })
            ->orderBy('due.datetime_value')
            ->select(['t.id', 't.title', 'due.datetime_value as due_at', 'pr.string_value as priority_option_id'])
            ->get();
    }

    /**
     * @return array<string, object{name: string, color: ?string}>
     */
    private function priorityOptions(?string $priorityFieldId, bool $withColors): array
    {
        if ($priorityFieldId === null) {
            return [];
        }

        // Read once, never joined per task: production has no index on custom_field_options,
        // and the per-row join planned as a nested loop that took 22 s for a 36-task digest.
        return DB::table('custom_field_options')
            ->where('custom_field_id', $priorityFieldId)
            ->select(['id', 'name'])
            ->selectRaw("settings->>'color' as color")
            ->get()
            ->mapWithKeys(fn (object $option): array => [(string) $option->id => (object) ['name' => (string) $option->name, 'color' => $withColors ? $option->color : null]])
            ->all();
    }

    /**
     * @param  list<string>  $taskIds
     * @return array<string, list<DigestRecord>>
     */
    private function linkedRecords(Workspace $workspace, array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        $records = [];

        foreach (CrmEntity::linkable() as $entity) {
            $rows = DB::table('taskables as link')
                ->join($entity->table().' as record', 'record.id', '=', 'link.taskable_id')
                ->whereIn('link.task_id', $taskIds)
                ->where('link.taskable_type', $entity->value)
                ->where('record.workspace_id', $workspace->getKey())
                ->whereNull('record.deleted_at')
                ->orderBy('record.'.$entity->titleColumn())
                ->get(['link.task_id', 'record.'.$entity->titleColumn().' as name']);

            foreach ($rows as $row) {
                $records[(string) $row->task_id][] = new DigestRecord($entity, (string) $row->name);
            }
        }

        return $records;
    }

    /**
     * @return array{due_field_id: ?string, status_field_id: ?string, done_option_id: ?string, priority_field_id: ?string, priority_colors: bool}
     */
    private function resolveFieldMetadata(Workspace $workspace): array
    {
        $row = DB::table('custom_fields as cf')
            ->leftJoin('custom_field_options as opt', function (JoinClause $join): void {
                $join->on('opt.custom_field_id', '=', 'cf.id')
                    ->where('opt.name', '=', TaskField::DONE_STATUS);
            })
            ->where('cf.tenant_id', $workspace->getKey())
            ->where('cf.entity_type', 'task')
            ->whereIn('cf.code', ['due_date', 'status', 'priority'])
            ->selectRaw(implode(', ', [
                "MAX(CASE WHEN cf.code = 'due_date' THEN cf.id END) AS due_field_id",
                "MAX(CASE WHEN cf.code = 'status' THEN cf.id END) AS status_field_id",
                "MAX(CASE WHEN cf.code = 'status' THEN opt.id END) AS done_option_id",
                "MAX(CASE WHEN cf.code = 'priority' THEN cf.id END) AS priority_field_id",
                "BOOL_OR(cf.code = 'priority' AND (cf.settings->>'enable_option_colors')::boolean) AS priority_colors",
            ]))
            ->first();

        return [
            'due_field_id' => $row?->due_field_id !== null ? (string) $row->due_field_id : null,
            'status_field_id' => $row?->status_field_id !== null ? (string) $row->status_field_id : null,
            'done_option_id' => $row?->done_option_id !== null ? (string) $row->done_option_id : null,
            'priority_field_id' => $row?->priority_field_id !== null ? (string) $row->priority_field_id : null,
            'priority_colors' => (bool) $row?->priority_colors,
        ];
    }
}
