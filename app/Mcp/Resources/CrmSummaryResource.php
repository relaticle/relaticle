<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Enums\CustomFields\OpportunityField;
use App\Enums\CustomFields\TaskField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Description('CRM summary with record counts, pipeline breakdown by stage, and task status. Use for overview and analytics questions.')]
#[Uri('relaticle://summary/crm')]
#[MimeType('application/json')]
final class CrmSummaryResource extends Resource
{
    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $teamId = $user->currentTeam->getKey();

        $cacheKey = "crm_summary_{$teamId}";

        $summary = Cache::remember($cacheKey, 60, fn (): array => [
            'companies' => ['total' => Company::query()->where('team_id', $teamId)->count()],
            'people' => ['total' => People::query()->where('team_id', $teamId)->count()],
            'opportunities' => $this->opportunitySummary($teamId),
            'tasks' => $this->taskSummary($teamId),
            'notes' => ['total' => Note::query()->where('team_id', $teamId)->count()],
        ]);

        return Response::text(json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function opportunitySummary(mixed $teamId): array
    {
        $total = Opportunity::query()->where('team_id', $teamId)->count();

        $stageFieldId = $this->resolveFieldId($teamId, 'opportunity', OpportunityField::STAGE->value);
        $amountFieldId = $this->resolveFieldId($teamId, 'opportunity', OpportunityField::AMOUNT->value);

        if ($stageFieldId === null) {
            return ['total' => $total];
        }

        $amountJoin = $amountFieldId !== null
            ? "LEFT JOIN custom_field_values amount_cfv ON amount_cfv.entity_id = o.id AND amount_cfv.entity_type = 'opportunity' AND amount_cfv.custom_field_id = ?"
            : '';
        $amountSelect = $amountFieldId !== null
            ? 'COALESCE(SUM(amount_cfv.float_value), 0) as total_amount'
            : '0 as total_amount';
        $amountBindings = $amountFieldId !== null ? [$amountFieldId] : [];

        $rows = DB::select(
            "SELECT stage_cfv.string_value as stage, COUNT(*) as count, {$amountSelect}
             FROM opportunities o
             LEFT JOIN custom_field_values stage_cfv ON stage_cfv.entity_id = o.id AND stage_cfv.entity_type = 'opportunity' AND stage_cfv.custom_field_id = ?
             {$amountJoin}
             WHERE o.team_id = ? AND o.deleted_at IS NULL
             GROUP BY stage_cfv.string_value",
            [$stageFieldId, ...$amountBindings, $teamId],
        );

        $byStage = [];
        $totalPipeline = 0;
        $totalWon = 0;

        foreach ($rows as $row) {
            $stage = $row->stage ?? 'Unknown';
            $amount = (float) $row->total_amount;
            $byStage[$stage] = ['count' => (int) $row->count, 'total_amount' => $amount];
            $totalPipeline += $amount;

            if (str_contains(strtolower($stage), 'won')) {
                $totalWon += $amount;
            }
        }

        return [
            'total' => $total,
            'by_stage' => $byStage,
            'total_pipeline_value' => $totalPipeline,
            'total_won_value' => $totalWon,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function taskSummary(mixed $teamId): array
    {
        $total = Task::query()->where('team_id', $teamId)->count();

        $dueDateFieldId = $this->resolveFieldId($teamId, 'task', TaskField::DUE_DATE->value);

        if ($dueDateFieldId === null) {
            return ['total' => $total, 'overdue' => 0, 'due_this_week' => 0];
        }

        $row = DB::selectOne(
            "SELECT
                COUNT(*) FILTER (WHERE due_cfv.datetime_value::date < CURRENT_DATE) as overdue,
                COUNT(*) FILTER (WHERE due_cfv.datetime_value::date BETWEEN CURRENT_DATE AND (CURRENT_DATE + INTERVAL '7 days')) as due_this_week
             FROM tasks t
             LEFT JOIN custom_field_values due_cfv ON due_cfv.entity_id = t.id AND due_cfv.entity_type = 'task' AND due_cfv.custom_field_id = ?
             WHERE t.team_id = ? AND t.deleted_at IS NULL",
            [$dueDateFieldId, $teamId],
        );

        return [
            'total' => $total,
            'overdue' => (int) ($row->overdue ?? 0),
            'due_this_week' => (int) ($row->due_this_week ?? 0),
        ];
    }

    private function resolveFieldId(mixed $teamId, string $entityType, string $code): mixed
    {
        return CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $teamId)
            ->where('entity_type', $entityType)
            ->where('code', $code)
            ->active()
            ->value('id');
    }
}
