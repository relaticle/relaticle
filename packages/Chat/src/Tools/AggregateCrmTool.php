<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use App\Actions\Opportunity\AggregateOpportunities;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
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

    /**
     * Group labels for rows with nothing on the other side of the join. Deliberately
     * untranslated: every tool payload is English, and the model grounds its answer
     * on these labels before writing a reply in the user's own language.
     */
    private const string NO_COMPANY_LABEL = 'No company';

    private const string UNSET_OPTION_LABEL = 'Unset';

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

        $countOnly = $groupBy === 'people_per_company' || in_array($groupBy, ['task_status', 'task_priority'], true);

        // Only the opportunity groupings honour a date range. Answering a dated
        // question with all-time counts would hand the model a confident wrong
        // number, so refuse instead of silently ignoring the filter.
        if ($countOnly && ($dateFrom !== null || $dateTo !== null)) {
            return (string) json_encode([
                'error' => "date_from and date_to only apply to the \"stage\" and \"company\" groupings. Re-run \"{$groupBy}\" without a date range.",
            ], JSON_UNESCAPED_SLASHES);
        }

        if ($groupBy === 'people_per_company') {
            return $this->aggregatePeoplePerCompany($user);
        }

        if ($countOnly) {
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

        // Rooted in companies, not people: a company with no contacts has to appear
        // as a zero row. Grouped from the people side it would be missing entirely,
        // and "which company has the most contacts" would be answered off a list
        // that silently drops every company the answer might be about.
        $rows = Company::query()
            ->whereBelongsTo($team)
            ->leftJoin('people', function (JoinClause $join) use ($team): void {
                $join->on('people.company_id', '=', 'companies.id')
                    ->whereNull('people.deleted_at')
                    ->where('people.team_id', $team->getKey());
            })
            ->groupBy('companies.id', 'companies.name')
            ->selectRaw('companies.name as label, count(people.id) as count')
            ->orderByDesc('count')
            ->limit(self::MAX_COMPANY_GROUPS)
            ->get();

        $mappedRows = $rows->map(fn (Company $row): array => [
            'label' => (string) $row->getAttribute('label'),
            'count' => (int) $row->getAttribute('count'),
        ])->all();

        // Contacts attached to no company at all, plus any left pointing at a
        // deleted one: they are in total_count, so they need a row of their own.
        $unassigned = People::query()->whereBelongsTo($team)->whereDoesntHave('company')->count();

        if ($unassigned > 0) {
            $mappedRows[] = ['label' => self::NO_COMPANY_LABEL, 'count' => $unassigned];
            $mappedRows = collect($mappedRows)->sortByDesc('count')->values()->all();
        }

        // Counted separately rather than summed off $rows: the group list is
        // capped, so summing it would under-report the moment a team has more
        // than MAX_COMPANY_GROUPS companies. Mirrors AggregateOpportunities::grandTotals().
        return (string) json_encode([
            'group_by' => 'people_per_company',
            'rows' => $mappedRows,
            'total_count' => People::query()->whereBelongsTo($team)->count(),
            'truncated' => $rows->count() === self::MAX_COMPANY_GROUPS,
        ], JSON_UNESCAPED_SLASHES);
    }

    private function aggregateTasksByOption(User $user, string $code, string $groupBy): string
    {
        abort_unless($user->can('viewAny', Task::class), 403);

        /** @var Team $team */
        $team = $user->currentTeam;
        $tenantId = (string) $team->getKey();

        // Tenant filtered explicitly rather than through the ambient scope: this tool
        // runs inside the queued chat job, where no Filament tenant is bound.
        $field = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('entity_type', 'task')
            ->where('code', $code)
            ->active()
            ->first();

        // Counting every task as "Unset" against a field the workspace has switched
        // off would hand the model a confident wrong number. Say it is unavailable.
        if ($field === null) {
            return (string) json_encode([
                'error' => "This workspace has no active \"{$code}\" field on tasks, so tasks cannot be grouped by it.",
            ], JSON_UNESCAPED_SLASHES);
        }

        // Which column holds the value is the custom-fields package's decision, not
        // ours. Asking the field keeps this join correct if that ever moves.
        $valueColumn = $field->getValueColumn();

        $rows = Task::query()
            ->whereBelongsTo($team)
            ->leftJoin('custom_field_values as cfv', function (JoinClause $join) use ($tenantId, $field): void {
                $join->on('cfv.entity_id', '=', 'tasks.id')
                    ->where('cfv.entity_type', 'task')
                    ->where('cfv.tenant_id', $tenantId)
                    ->where('cfv.custom_field_id', $field->getKey());
            })
            ->leftJoin('custom_field_options as cfo', 'cfo.id', '=', "cfv.{$valueColumn}")
            ->groupBy('cfo.id', 'cfo.name')
            ->selectRaw('coalesce(cfo.name, ?) as label, count(distinct tasks.id) as count', [self::UNSET_OPTION_LABEL])
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
