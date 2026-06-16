<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\CustomFields\EnsureTagOptionsExist;
use App\Models\CustomFieldValue;

final readonly class CustomFieldValueObserver
{
    public function __construct(
        private EnsureTagOptionsExist $ensureTagOptionsExist,
    ) {}

    public function saved(CustomFieldValue $value): void
    {
        // Only multi-value fields (tags-input et al.) store an array in json_value;
        // scalar-typed fields leave it blank. Short-circuit before loading the
        // customField relation so ordinary custom-field saves incur no extra query.
        if (blank($value->json_value)) {
            return;
        }

        $field = $value->customField;

        // @phpstan-ignore identical.alwaysFalse (the customField relation can resolve to null for an orphaned value row)
        if ($field === null) {
            return;
        }

        $this->ensureTagOptionsExist->execute($field, $value->json_value);
    }
}
