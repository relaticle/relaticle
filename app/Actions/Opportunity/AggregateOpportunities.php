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
     * Cap on the number of grouped rows returned. Grand totals are computed
     * separately so they stay accurate even when groups exceed this cap.
     */
    private const int MAX_GROUPS = 100;

    /**
     * Aggregate opportunities by stage or company.
     *
     * @return array{group_by: string, rows: list<array{label: string, count: int, total_amount: float}>, total_count: int, total_amount: float, truncated: bool}
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
     * @return array{group_by: string, rows: list<array{label: string, count: int, total_amount: float}>, total_count: int, total_amount: float, truncated: bool}
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

        $totals = $this->grandTotals($teamId, $amountFieldId, $dateFrom, $dateTo);

        if ($stageFieldId === null) {
            $mappedRows = [[
                'label' => 'Unspecified',
                'count' => $totals['count'],
                'total_amount' => $totals['amount'],
            ]];

            return $this->buildResult('stage', $mappedRows, $totals['count'], $totals['amount']);
        }

        $rows = DB::select(
            "SELECT stage_cfv.string_value as stage_option_id, COUNT(*) as count, {$amountSelect}
             FROM opportunities o
             LEFT JOIN custom_field_values stage_cfv ON stage_cfv.entity_id = o.id AND stage_cfv.entity_type = 'opportunity' AND stage_cfv.custom_field_id = ?
             {$amountJoin}
             WHERE o.team_id = ? AND o.deleted_at IS NULL{$dateClause}
             GROUP BY stage_cfv.string_value
             ORDER BY count DESC
             LIMIT ".self::MAX_GROUPS,
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

        return $this->buildResult('stage', $mappedRows, $totals['count'], $totals['amount']);
    }

    /**
     * @return array{group_by: string, rows: list<array{label: string, count: int, total_amount: float}>, total_count: int, total_amount: float, truncated: bool}
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
             LIMIT ".self::MAX_GROUPS,
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

        $totals = $this->grandTotals($teamId, $amountFieldId, $dateFrom, $dateTo);

        return $this->buildResult('company', $mappedRows, $totals['count'], $totals['amount']);
    }

    /**
     * Grand totals across ALL matching opportunities, independent of the grouped
     * row cap, so reported counts and pipeline value stay correct when the number
     * of groups exceeds the cap.
     *
     * @return array{count: int, amount: float}
     */
    private function grandTotals(mixed $teamId, mixed $amountFieldId, ?string $dateFrom, ?string $dateTo): array
    {
        $dateClause = $this->dateClause($dateFrom, $dateTo);
        $dateBindings = $this->dateBindings($dateFrom, $dateTo);

        $amountJoin = $amountFieldId !== null
            ? "LEFT JOIN custom_field_values amount_cfv ON amount_cfv.entity_id = o.id AND amount_cfv.entity_type = 'opportunity' AND amount_cfv.custom_field_id = ?"
            : '';
        $amountSelect = $amountFieldId !== null
            ? 'COALESCE(SUM(amount_cfv.float_value), 0) as total_amount'
            : '0 as total_amount';
        $amountBindings = $amountFieldId !== null ? [$amountFieldId] : [];

        $row = DB::select(
            "SELECT COUNT(*) as count, {$amountSelect}
             FROM opportunities o
             {$amountJoin}
             WHERE o.team_id = ? AND o.deleted_at IS NULL{$dateClause}",
            [...$amountBindings, $teamId, ...$dateBindings],
        );

        return [
            'count' => (int) ($row[0]->count ?? 0),
            'amount' => (float) ($row[0]->total_amount ?? 0),
        ];
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
     * @return array{group_by: string, rows: list<array{label: string, count: int, total_amount: float}>, total_count: int, total_amount: float, truncated: bool}
     */
    private function buildResult(string $groupBy, array $rows, int $totalCount, float $totalAmount): array
    {
        return [
            'group_by' => $groupBy,
            'rows' => $rows,
            'total_count' => $totalCount,
            'total_amount' => $totalAmount,
            'truncated' => count($rows) >= self::MAX_GROUPS,
        ];
    }
}
