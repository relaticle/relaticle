<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Concerns;

use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\Models\CustomFieldValue;

trait FormatsCustomFields
{
    /**
     * @return array<string, mixed>
     */
    protected function formatCustomFields(Model $record): array
    {
        if (! $record->relationLoaded('customFieldValues')) {
            return [];
        }

        return $record->getRelation('customFieldValues')
            /** @phpstan-ignore notIdentical.alwaysTrue (orphaned values can exist when a custom field is deleted) */
            ->filter(fn (CustomFieldValue $fieldValue): bool => $fieldValue->customField !== null)
            ->mapWithKeys(fn (CustomFieldValue $fieldValue): array => [
                $fieldValue->customField->code => $fieldValue->getValue(),
            ])
            ->all();
    }
}
