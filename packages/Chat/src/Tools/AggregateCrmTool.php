<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use App\Actions\Opportunity\AggregateOpportunities;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AggregateCrmTool implements Tool
{
    /**
     * Cap on the number of grouped rows returned for the people-per-company grouping.
     */
    private const int MAX_COMPANY_GROUPS = 50;

    public function description(): string
    {
        return 'Aggregate CRM records into counted groups. "stage" or "company" aggregate opportunities: count and '
            .'total pipeline value per group, with optional date range. "people_per_company" counts contacts per '
            .'company. "task_status" and "task_priority" count tasks by their option label. Use for "pipeline by '
            .'stage", "deals by company", "total value", "how many deals", "which company has the most contacts", '
            .'or "how many tasks are done" questions.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'group_by' => $schema->string()->description(
                'How to group results. "stage" or "company" aggregate deal value. "people_per_company" counts '
                .'contacts per company. "task_status" and "task_priority" count tasks by their option label.'
            ),
            'date_from' => $schema->string()->description('Optional start date (YYYY-MM-DD). Only include opportunities created on or after this date.'),
            'date_to' => $schema->string()->description('Optional end date (YYYY-MM-DD). Only include opportunities created on or before this date.'),
        ];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        $groupBy = (string) ($request['group_by'] ?? 'stage');
        $dateFrom = isset($request['date_from']) && is_string($request['date_from']) ? $request['date_from'] : null;
        $dateTo = isset($request['date_to']) && is_string($request['date_to']) ? $request['date_to'] : null;

        if ($groupBy === 'people_per_company') {
            return $this->aggregatePeoplePerCompany($user);
        }

        if (in_array($groupBy, ['task_status', 'task_priority'], true)) {
            return $this->aggregateTasksByOption($user, $groupBy === 'task_status' ? 'status' : 'priority', $groupBy);
        }

        try {
            $result = resolve(AggregateOpportunities::class)->execute(
                user: $user,
                groupBy: $groupBy,
                dateFrom: $dateFrom,
                dateTo: $dateTo,
            );
        } catch (HttpException $e) {
            return (string) json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
        }

        return (string) json_encode($result, JSON_UNESCAPED_SLASHES);
    }

    private function aggregatePeoplePerCompany(User $user): string
    {
        abort_unless($user->can('viewAny', People::class), 403);

        /** @var Team $team */
        $team = $user->currentTeam;

        $rows = People::query()
            ->whereBelongsTo($team)
            ->leftJoin('companies', function (JoinClause $join): void {
                $join->on('companies.id', '=', 'people.company_id')->whereNull('companies.deleted_at');
            })
            ->groupByRaw("coalesce(companies.name, 'No company')")
            ->selectRaw("coalesce(companies.name, 'No company') as label, count(*) as count")
            ->orderByDesc('count')
            ->limit(self::MAX_COMPANY_GROUPS)
            ->get();

        $mappedRows = $rows->map(fn (People $row): array => [
            'label' => (string) $row->getAttribute('label'),
            'count' => (int) $row->getAttribute('count'),
        ])->all();

        return (string) json_encode([
            'group_by' => 'people_per_company',
            'rows' => $mappedRows,
            'total_count' => (int) $rows->sum('count'),
            'truncated' => $rows->count() === self::MAX_COMPANY_GROUPS,
        ], JSON_UNESCAPED_SLASHES);
    }

    private function aggregateTasksByOption(User $user, string $code, string $groupBy): string
    {
        abort_unless($user->can('viewAny', Task::class), 403);

        /** @var Team $team */
        $team = $user->currentTeam;
        $tenantId = (string) $team->getKey();

        $rows = Task::query()
            ->whereBelongsTo($team)
            ->leftJoin('custom_field_values as cfv', function (JoinClause $join) use ($tenantId): void {
                $join->on('cfv.entity_id', '=', 'tasks.id')
                    ->where('cfv.entity_type', 'task')
                    ->where('cfv.tenant_id', $tenantId);
            })
            ->leftJoin('custom_fields as cf', function (JoinClause $join) use ($code): void {
                $join->on('cf.id', '=', 'cfv.custom_field_id')->where('cf.code', $code);
            })
            ->leftJoin('custom_field_options as cfo', 'cfo.id', '=', 'cfv.string_value')
            ->where(function (Builder $query): void {
                $query->whereNotNull('cf.id')->orWhereNull('cfv.id');
            })
            ->groupByRaw("coalesce(cfo.name, 'Unset')")
            ->selectRaw("coalesce(cfo.name, 'Unset') as label, count(distinct tasks.id) as count")
            ->orderByDesc('count')
            ->get();

        $mappedRows = $rows->map(fn (Task $row): array => [
            'label' => (string) $row->getAttribute('label'),
            'count' => (int) $row->getAttribute('count'),
        ])->all();

        return (string) json_encode([
            'group_by' => $groupBy,
            'rows' => $mappedRows,
            'total_count' => (int) $rows->sum('count'),
            'truncated' => false,
        ], JSON_UNESCAPED_SLASHES);
    }
}
