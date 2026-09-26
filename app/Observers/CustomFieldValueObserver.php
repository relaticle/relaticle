<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\CustomFields\EnsureTagOptionsExist;
use App\Models\CustomFieldValue;
use App\Support\ActivityLog\CustomFieldChangeLog;
use App\Support\Media\UploadClaims;
use Relaticle\CustomFields\Models\CustomField;

final readonly class CustomFieldValueObserver
{
    public function __construct(
        private EnsureTagOptionsExist $ensureTagOptionsExist,
        private UploadClaims $uploadClaims,
        private CustomFieldChangeLog $changeLog,
    ) {}

    public function saving(CustomFieldValue $value): void
    {
        $this->uploadClaims->assertClaimable($value);
    }

    public function saved(CustomFieldValue $value): void
    {
        if ($value->wasRecentlyCreated || $value->wasChanged(['string_value', 'text_value'])) {
            $this->uploadClaims->sync($value);
        }

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

    public function created(CustomFieldValue $value): void
    {
        $this->changeLog->record($value->entity, $value->customField, null, $value->getValue());
    }

    public function updated(CustomFieldValue $value): void
    {
        $column = CustomFieldValue::getValueColumn($value->customField->type);

        if (! $value->wasChanged($column)) {
            return;
        }

        $this->changeLog->record($value->entity, $value->customField, $value->getOriginal($column), $value->getValue());
    }
}
