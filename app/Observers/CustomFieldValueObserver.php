<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\CustomFields\EnsureTagOptionsExist;
use App\Models\CustomFieldValue;
use App\Support\ActivityLog\CustomFieldChangeLog;
use App\Support\Media\UploadClaims;
use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\Models\CustomField;
use Relaticle\CustomFields\Models\CustomFieldRelationship;

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
        if ($this->readsLinks($value)) {
            return;
        }

        $this->log($value, old: null);
    }

    public function updated(CustomFieldValue $value): void
    {
        if ($this->readsLinks($value)) {
            return;
        }

        $column = CustomFieldValue::getValueColumn($value->customField->type);

        if (! $value->wasChanged($column)) {
            return;
        }

        $this->log($value, $value->getOriginal($column));
    }

    /**
     * A field that links records keeps its history in the edge ledger, and
     * LogLinkChangeListener writes the timeline entry for both records from the one
     * event. Any value row left on such a field is pre-migration residue: logging it
     * here would double the entry, or announce an id as the value.
     */
    private function readsLinks(CustomFieldValue $value): bool
    {
        return $value->customField->relationshipDefinition() instanceof CustomFieldRelationship;
    }

    private function log(CustomFieldValue $value, mixed $old): void
    {
        $entity = $value->getRelationValue('entity');

        if (! $entity instanceof Model) {
            return;
        }

        $this->changeLog->record($entity, $value->customField, $old, $value->getValue());
    }
}
