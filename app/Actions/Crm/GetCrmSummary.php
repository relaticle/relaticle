<?php

declare(strict_types=1);

namespace App\Actions\Crm;

use App\Actions\Opportunity\AggregateOpportunities;
use App\Enums\CrmEntity;
use App\Enums\CustomFields\TaskField;
use App\Models\Company;
use App\Models\Note;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

final readonly class GetCrmSummary
{
    public function __construct(
        private AggregateOpportunities $aggregateOpportunities,
    ) {}

    /** @return array<string, mixed> */
    public function execute(User $user): array
    {
        foreach (CrmEntity::cases() as $entity) {
            abort_unless($user->can('viewAny', $entity->model()), 403);
        }

        $teamId = (string) $user->currentTeam->getKey();
        $timezone = $user->effectiveTimezone();
        $today = Date::now($timezone)->startOfDay();
        $cacheKey = "crm_summary_{$teamId}_{$timezone}_{$today->toDateString()}";

        return Cache::remember($cacheKey, 60, function () use ($user, $teamId, $timezone, $today): array {
            $opportunities = $this->aggregateOpportunities->execute($user, 'stage');
            $rows = collect($opportunities['rows']);

            // Two stage options may share a name, so group rather than assign by label:
            // assigning would drop every row but the last of each group.
            $byStage = $rows
                ->groupBy('label')
                ->map(fn (Collection $group): array => [
                    'count' => (int) $group->sum('count'),
                    'total_amount' => (float) $group->sum('total_amount'),
                ])
                ->all();

            return [
                'as_of' => [
                    'date' => $today->toDateString(),
                    'timezone' => $timezone,
                ],
                'companies' => ['total' => Company::query()->where('team_id', $teamId)->count()],
                'people' => ['total' => People::query()->where('team_id', $teamId)->count()],
                'opportunities' => [
                    'total' => $opportunities['total_count'],
                    'by_stage' => $byStage,
                    'total_pipeline_value' => $opportunities['total_amount'],
                    'truncated' => $opportunities['truncated'],
                ],
                'tasks' => $this->taskSummary($teamId, $today->clone()->utc(), $today->clone()->addDays(7)->utc()),
                'notes' => ['total' => Note::query()->where('team_id', $teamId)->count()],
            ];
        });
    }

    /** @return array{total: int, overdue: int, due_this_week: int} */
    private function taskSummary(string $teamId, DateTimeInterface $todayUtc, DateTimeInterface $weekEndUtc): array
    {
        $total = Task::query()->where('team_id', $teamId)->count();
        $fields = $this->taskFieldMetadata($teamId);
        $dueDateFieldId = $fields['due_field_id'];

        if ($dueDateFieldId === null) {
            return ['total' => $total, 'overdue' => 0, 'due_this_week' => 0];
        }

        $row = DB::table('tasks as task')
            ->leftJoin('custom_field_values as due_cfv', function (JoinClause $join) use ($dueDateFieldId): void {
                $join->on('due_cfv.entity_id', '=', 'task.id')
                    ->where('due_cfv.entity_type', 'task')
                    ->where('due_cfv.custom_field_id', $dueDateFieldId);
            })
            ->where('task.team_id', $teamId)
            ->whereNull('task.deleted_at')
            ->when($fields['done_option_id'] !== null, function (QueryBuilder $query) use ($fields): void {
                $query->whereNotExists(function (QueryBuilder $status) use ($fields): void {
                    $status->select(DB::raw(1))
                        ->from('custom_field_values as status_cfv')
                        ->whereColumn('status_cfv.entity_id', 'task.id')
                        ->where('status_cfv.entity_type', 'task')
                        ->where('status_cfv.custom_field_id', $fields['status_field_id'])
                        ->where('status_cfv.string_value', $fields['done_option_id']);
                });
            })
            ->selectRaw(
                'COUNT(*) FILTER (WHERE due_cfv.datetime_value < ?) as overdue,
                 COUNT(*) FILTER (WHERE due_cfv.datetime_value >= ? AND due_cfv.datetime_value < ?) as due_this_week',
                [$todayUtc, $todayUtc, $weekEndUtc],
            )
            ->first();

        return [
            'total' => $total,
            'overdue' => (int) ($row->overdue ?? 0),
            'due_this_week' => (int) ($row->due_this_week ?? 0),
        ];
    }

    /** @return array{due_field_id: ?string, status_field_id: ?string, done_option_id: ?string} */
    private function taskFieldMetadata(string $teamId): array
    {
        $row = DB::table('custom_fields as field')
            ->leftJoin('custom_field_options as option', function (JoinClause $join): void {
                $join->on('option.custom_field_id', '=', 'field.id')
                    ->where('option.name', 'Done');
            })
            ->where('field.tenant_id', $teamId)
            ->where('field.entity_type', 'task')
            ->where('field.active', true)
            ->whereIn('field.code', [TaskField::DUE_DATE->value, TaskField::STATUS->value])
            ->selectRaw(implode(', ', [
                'MAX(CASE WHEN field.code = ? THEN field.id END) AS due_field_id',
                'MAX(CASE WHEN field.code = ? THEN field.id END) AS status_field_id',
                'MAX(CASE WHEN field.code = ? THEN option.id END) AS done_option_id',
            ]), [TaskField::DUE_DATE->value, TaskField::STATUS->value, TaskField::STATUS->value])
            ->first();

        return [
            'due_field_id' => $row?->due_field_id !== null ? (string) $row->due_field_id : null,
            'status_field_id' => $row?->status_field_id !== null ? (string) $row->status_field_id : null,
            'done_option_id' => $row?->done_option_id !== null ? (string) $row->done_option_id : null,
        ];
    }
}
