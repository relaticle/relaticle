<?php

declare(strict_types=1);

namespace App\Actions\Merge;

use App\Data\MergedValues;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\People;
use App\Support\CustomFieldSettingsSchema;

final readonly class RaiseFieldLimits
{
    public function execute(Company|People $merged, MergedValues $mergedValues): void
    {
        foreach ($mergedValues->raises as $code => $count) {
            $field = CustomField::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $merged->workspace_id)
                ->where('entity_type', $merged->getMorphClass())
                ->where('code', $code)
                ->lockForUpdate()
                ->first();

            if ($field === null || $field->settings->max_values >= $count) {
                continue;
            }

            $field->update(['settings' => CustomFieldSettingsSchema::apply($field, ['max_values' => $count])]);
        }
    }
}
