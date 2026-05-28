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
        $field = $value->customField;

        // @phpstan-ignore identical.alwaysFalse (the customField relation can resolve to null for an orphaned value row)
        if ($field === null) {
            return;
        }

        $this->ensureTagOptionsExist->handle($field, $value->json_value);
    }
}
