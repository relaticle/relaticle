<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\CustomFields\EnsureTagOptionsExist;
use App\Models\Activity;
use App\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

final readonly class CustomFieldValueObserver
{
    private const int MERGE_WINDOW_SECONDS = 5;

    public function __construct(
        private EnsureTagOptionsExist $ensureTagOptionsExist,
    ) {}

    public function created(CustomFieldValue $customFieldValue): void
    {
        $this->logChange($customFieldValue, oldValue: null, newValue: $customFieldValue->getValue());
    }

    public function updated(CustomFieldValue $customFieldValue): void
    {
        $valueColumn = CustomFieldValue::getValueColumn($customFieldValue->customField->type);

        if (! $customFieldValue->wasChanged($valueColumn)) {
            return;
        }

        $this->logChange(
            $customFieldValue,
            oldValue: $customFieldValue->getOriginal($valueColumn),
            newValue: $customFieldValue->getValue(),
        );
    }

    public function deleted(CustomFieldValue $customFieldValue): void
    {
        $this->logChange($customFieldValue, oldValue: $customFieldValue->getValue(), newValue: null);
    }

    private function logChange(CustomFieldValue $customFieldValue, mixed $oldValue, mixed $newValue): void
    {
        $entity = $customFieldValue->entity;

        if (! in_array(LogsActivity::class, class_uses_recursive($entity::class), true)) {
            return;
        }

        if ($oldValue === $newValue) {
            return;
        }

        $fieldKey = $customFieldValue->customField->code ?? $customFieldValue->customField->name;
        $causer = auth()->user();

        if ($this->mergeIntoRecentActivity($entity, $causer?->getKey(), $fieldKey, $oldValue, $newValue)) {
            return;
        }

        activity()
            ->performedOn($entity)
            ->causedBy($causer)
            ->event('updated')
            ->withChanges([
                'attributes' => [$fieldKey => $newValue],
                'old' => [$fieldKey => $oldValue],
            ])
            ->log('updated');
    }

    private function mergeIntoRecentActivity(
        Model $entity,
        int|string|null $causerKey,
        string $fieldKey,
        mixed $oldValue,
        mixed $newValue,
    ): bool {
        $recent = Activity::query()
            ->where('subject_type', $entity->getMorphClass())
            ->where('subject_id', $entity->getKey())
            ->where('event', 'updated')
            ->where('causer_id', $causerKey)
            ->where('created_at', '>=', now()->subSeconds(self::MERGE_WINDOW_SECONDS))
            ->latest('id')
            ->first();

        if ($recent === null) {
            return false;
        }

        $changes = $recent->attribute_changes?->toArray() ?? [];
        $changes['attributes'] = array_merge($changes['attributes'] ?? [], [$fieldKey => $newValue]);
        $changes['old'] = array_merge($changes['old'] ?? [], [$fieldKey => $oldValue]);

        $recent->attribute_changes = collect($changes);
        $recent->save();

        return true;
    }

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

        $this->ensureTagOptionsExist->handle($field, $value->json_value);
    }
}
