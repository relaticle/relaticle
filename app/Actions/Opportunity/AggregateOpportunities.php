<?php

declare(strict_types=1);

namespace App\Actions\Opportunity;

use App\Enums\CustomFields\OpportunityField;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class AggregateOpportunities
{
    /**
     * Aggregate opportunities by stage or company.
     *
     * @return array{group_by: string, rows: list<array{label: string, count: int, total_amount: float}>, total_count: int, total_amount: float}
     */
    public function execute(
        User $user,
        string $groupBy,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): array {
        abort_unless($user->can('viewAny', Opportunity::class), 403);

        $teamId = $user->currentTeam->getKey();

        return match ($groupBy) {
            'stage' => $this->byStage($teamId, $dateFrom, $dateTo),
            'company' => $this->byCompany($teamId, $dateFrom, $dateTo),
            default => abort(422, "Invalid group_by value: {$groupBy}. Must be 'stage' or 'company'."),
        };
    }

    /**
     * @return array{group_by: string, rows: list<array{label: string, count: int, total_amount: float}>, total_count: int, total_amount: float}
     */
    private function byStage(mixed $teamId, ?string $dateFrom, ?string $dateTo): array
    {
        $stageFieldId = $this->resolveFieldId($teamId, OpportunityField::STAGE->value);
        $amountFieldId = $this->resolveFieldId($teamId, OpportunityField::AMOUNT->value);

        $dateClause = $this->dateClause($dateFrom, $dateTo);
        $dateBindings = $this->dateBindings($dateFrom, $dateTo);

        $amountJoin = $amountFieldId !== null
            ? "LEFT JOIN custom_field_values amount_cfv ON amount_cfv.entity_id = o.id AND amount_cfv.entity_type = 'opportunity' AND amount_cfv.custom_field_id = ?"
            : '';
        $amountSelect = $amountFieldId !== null
            ? 'COALESCE(SUM(amount_cfv.float_value), 0) as total_amount'
            : '0 as total_amount';
        $amountBindings = $amountFieldId !== null ? [$amountFieldId] : [];

        if ($stageFieldId === null) {
            $rows = DB::select(
                "SELECT 'Unspecified' as label, COUNT(*) as count, {$amountSelect}
                 FROM opportunities o
                 {$amountJoin}
                 WHERE o.team_id = ? AND o.deleted_at IS NULL{$dateClause}
                 LIMIT 100",
                [...$amountBindings, $teamId, ...$dateBindings],
            );

            $mappedRows = [[
                'label' => 'Unspecified',
                'count' => (int) ($rows[0]->count ?? 0),
                'total_amount' => (float) ($rows[0]->total_amount ?? 0),
            ]];

            return $this->buildResult('stage', $mappedRows);
        }

        $rows = DB::select(
            "SELECT stage_cfv.string_value as stage_option_id, COUNT(*) as count, {$amountSelect}
             FROM opportunities o
             LEFT JOIN custom_field_values stage_cfv ON stage_cfv.entity_id = o.id AND stage_cfv.entity_type = 'opportunity' AND stage_cfv.custom_field_id = ?
             {$amountJoin}
             WHERE o.team_id = ? AND o.deleted_at IS NULL{$dateClause}
             GROUP BY stage_cfv.string_value
             ORDER BY count DESC
             LIMIT 100",
            [$stageFieldId, ...$amountBindings, $teamId, ...$dateBindings],
        );

        $stageOptions = DB::table('custom_field_options')
            ->where('custom_field_id', $stageFieldId)
            ->pluck('name', 'id');

        $mappedRows = [];
        foreach ($rows as $row) {
            $optionId = $row->stage_option_id;
            $label = ($optionId !== null && isset($stageOptions[$optionId]))
                ? (string) $stageOptions[$optionId]
                : 'Unspecified';
            $mappedRows[] = [
                'label' => $label,
                'count' => (int) $row->count,
                'total_amount' => (float) $row->total_amount,
            ];
        }

        return $this->buildResult('stage', $mappedRows);
    }

    /**
     * @return array{group_by: string, rows: list<array{label: string, count: int, total_amount: float}>, total_count: int, total_amount: float}
     */
    private function byCompany(mixed $teamId, ?string $dateFrom, ?string $dateTo): array
    {
        $amountFieldId = $this->resolveFieldId($teamId, OpportunityField::AMOUNT->value);

        $dateClause = $this->dateClause($dateFrom, $dateTo);
        $dateBindings = $this->dateBindings($dateFrom, $dateTo);

        $amountJoin = $amountFieldId !== null
            ? "LEFT JOIN custom_field_values amount_cfv ON amount_cfv.entity_id = o.id AND amount_cfv.entity_type = 'opportunity' AND amount_cfv.custom_field_id = ?"
            : '';
        $amountSelect = $amountFieldId !== null
            ? 'COALESCE(SUM(amount_cfv.float_value), 0) as total_amount'
            : '0 as total_amount';
        $amountBindings = $amountFieldId !== null ? [$amountFieldId] : [];

        $rows = DB::select(
            "SELECT COALESCE(c.name, 'No Company') as label, COUNT(*) as count, {$amountSelect}
             FROM opportunities o
             LEFT JOIN companies c ON c.id = o.company_id AND c.deleted_at IS NULL
             {$amountJoin}
             WHERE o.team_id = ? AND o.deleted_at IS NULL{$dateClause}
             GROUP BY c.id, c.name
             ORDER BY count DESC
             LIMIT 100",
            [...$amountBindings, $teamId, ...$dateBindings],
        );

        $mappedRows = [];
        foreach ($rows as $row) {
            $mappedRows[] = [
                'label' => (string) $row->label,
                'count' => (int) $row->count,
                'total_amount' => (float) $row->total_amount,
            ];
        }

        return $this->buildResult('company', $mappedRows);
    }

    private function resolveFieldId(mixed $teamId, string $code): mixed
    {
        return CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $teamId)
            ->where('entity_type', 'opportunity')
            ->where('code', $code)
            ->active()
            ->value('id');
    }

    private function dateClause(?string $dateFrom, ?string $dateTo): string
    {
        $clause = '';

        if ($dateFrom !== null) {
            $clause .= ' AND o.created_at >= ?';
        }

        if ($dateTo !== null) {
            $clause .= ' AND o.created_at <= ?';
        }

        return $clause;
    }

    /**
     * @return list<string>
     */
    private function dateBindings(?string $dateFrom, ?string $dateTo): array
    {
        $bindings = [];

        if ($dateFrom !== null) {
            $bindings[] = $dateFrom;
        }

        if ($dateTo !== null) {
            $bindings[] = $dateTo.' 23:59:59';
        }

        return $bindings;
    }

    /**
     * @param  list<array{label: string, count: int, total_amount: float}>  $rows
     * @return array{group_by: string, rows: list<array{label: string, count: int, total_amount: float}>, total_count: int, total_amount: float}
     */
    private function buildResult(string $groupBy, array $rows): array
    {
        $totalCount = 0;
        $totalAmount = 0.0;

        foreach ($rows as $row) {
            $totalCount += $row['count'];
            $totalAmount += $row['total_amount'];
        }

        return [
            'group_by' => $groupBy,
            'rows' => $rows,
            'total_count' => $totalCount,
            'total_amount' => $totalAmount,
        ];
    }
}
