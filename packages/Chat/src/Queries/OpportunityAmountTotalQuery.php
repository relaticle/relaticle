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
     * The opportunities' summed Amount in the field's own currency, or null
     * when none of them holds an amount.
     *
     * @param  list<string>  $opportunityIds
     */
    public function formatted(string $workspaceId, array $opportunityIds): ?string
    {
        if ($opportunityIds === []) {
            return null;
        }

        $field = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $workspaceId)
            ->where('entity_type', 'opportunity')
            ->where('code', OpportunityField::AMOUNT->value)
            ->active()
            ->first();

        if (! $field instanceof CustomField) {
            return null;
        }

        $values = DB::table('custom_field_values')
            ->where('custom_field_id', $field->getKey())
            ->where('entity_type', 'opportunity')
            ->whereIn('entity_id', $opportunityIds)
            ->whereNotNull('float_value');

        if (! $values->exists()) {
            return null;
        }

        $total = Number::currency((float) $values->sum('float_value'), $field->getCurrencyCode(), precision: $field->getDecimalPlaces());

        return $total === false ? null : $total;
    }
}
