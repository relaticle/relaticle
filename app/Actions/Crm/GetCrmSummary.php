<?php

declare(strict_types=1);

namespace App\Actions\Crm;

use App\Actions\Opportunity\AggregateOpportunities;
use App\Enums\CustomFields\TaskField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Query\JoinClause;
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
        abort_unless($user->can('viewAny', Company::class), 403);
        abort_unless($user->can('viewAny', People::class), 403);
        abort_unless($user->can('viewAny', Opportunity::class), 403);
        abort_unless($user->can('viewAny', Task::class), 403);
        abort_unless($user->can('viewAny', Note::class), 403);

        $teamId = (string) $user->currentTeam->getKey();
        $timezone = $user->effectiveTimezone();
        $today = Date::now($timezone)->startOfDay();
        $cacheKey = "crm_summary_{$teamId}_{$timezone}_{$today->toDateString()}";

        return Cache::remember($cacheKey, 60, function () use ($user, $teamId, $timezone, $today): array {
            $opportunities = $this->aggregateOpportunities->execute($user, 'stage');
            $byStage = [];
            $totalWon = 0.0;

            foreach ($opportunities['rows'] as $row) {
                $byStage[$row['label']] = [
                    'count' => $row['count'],
                    'total_amount' => $row['total_amount'],
                ];

                if (str_contains(strtolower($row['label']), 'won')) {
                    $totalWon += $row['total_amount'];
                }
            }

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
                    'total_won_value' => $totalWon,
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
        $dueDateFieldId = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $teamId)
            ->where('entity_type', 'task')
            ->where('code', TaskField::DUE_DATE->value)
            ->active()
            ->value('id');

        if ($dueDateFieldId === null) {
            return ['total' => $total, 'overdue' => 0, 'due_this_week' => 0];
        }

        $row = DB::table('tasks')
            ->leftJoin('custom_field_values as due_cfv', function (JoinClause $join) use ($dueDateFieldId): void {
                $join->on('due_cfv.entity_id', '=', 'tasks.id')
                    ->where('due_cfv.entity_type', 'task')
                    ->where('due_cfv.custom_field_id', $dueDateFieldId);
            })
            ->where('tasks.team_id', $teamId)
            ->whereNull('tasks.deleted_at')
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
}
