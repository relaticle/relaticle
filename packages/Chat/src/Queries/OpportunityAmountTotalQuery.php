<?php

declare(strict_types=1);

namespace Relaticle\Chat\Queries;

use App\Enums\CustomFields\OpportunityField;
use App\Models\CustomField;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

final readonly class OpportunityAmountTotalQuery
{
    /**
     * Each group of opportunities' summed Amount in the field's own currency,
     * read in one query for every group. A group none of whose opportunities
     * holds an amount, or whose opportunities are deleted, is left out.
     *
     * @param  array<string, list<string>>  $idsByGroup
     * @return array<string, string>
     */
    public function formatted(string $workspaceId, array $idsByGroup): array
    {
        $ids = array_values(array_unique(array_merge(...array_values($idsByGroup) ?: [[]])));
        $field = $ids === [] ? null : $this->amountField($workspaceId);

        if (! $field instanceof CustomField) {
            return [];
        }

        $amounts = DB::table('custom_field_values as value')
            ->join('opportunities as opportunity', 'opportunity.id', '=', 'value.entity_id')
            ->where('opportunity.workspace_id', $workspaceId)
            ->whereNull('opportunity.deleted_at')
            ->where('value.custom_field_id', $field->getKey())
            ->where('value.entity_type', 'opportunity')
            ->whereIn('value.entity_id', $ids)
            ->whereNotNull('value.float_value')
            ->pluck('value.float_value', 'value.entity_id');

        $totals = [];

        foreach ($idsByGroup as $group => $groupIds) {
            $held = $amounts->only($groupIds);

            if ($held->isEmpty()) {
                continue;
            }

            $total = Number::currency((float) $held->sum(), $field->getCurrencyCode(), precision: $field->getDecimalPlaces());

            if ($total !== false) {
                $totals[$group] = $total;
            }
        }

        return $totals;
    }

    private function amountField(string $workspaceId): ?CustomField
    {
        return once(fn (): ?CustomField => CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $workspaceId)
            ->where('entity_type', 'opportunity')
            ->where('code', OpportunityField::AMOUNT->value)
            ->active()
            ->first());
    }
}
