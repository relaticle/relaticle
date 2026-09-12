<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\MediaCollection;
use App\Models\CustomFieldValue;
use App\Support\Media\MediaPaths;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Relaticle\CustomFields\Models\CustomField;

final readonly class StoredUploadPath implements ValidationRule
{
    public function __construct(
        private string $teamId,
        private string $entityType,
        private CustomField $field,
        private string|int|null $entityId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $media = is_string($value) ? resolve(MediaPaths::class)->find($this->teamId, $value) : null;

        if ($media === null) {
            $fail(__('validation.custom_field.upload_path', ['field' => $this->field->name]));

            return;
        }

        if ($media->collection_name === MediaCollection::PendingUploads->value) {
            return;
        }

        $ownsCurrentValue = $this->entityId !== null
            && $media->model_type === $this->entityType
            && (string) $media->model_id === (string) $this->entityId
            && $media->collection_name === MediaCollection::forCustomField($this->field->code)
            && CustomFieldValue::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $this->teamId)
                ->where('entity_type', $this->entityType)
                ->where('entity_id', $this->entityId)
                ->where('custom_field_id', $this->field->getKey())
                ->where('string_value', $value)
                ->exists();

        if ($ownsCurrentValue) {
            return;
        }

        $fail(__('validation.custom_field.upload_path', ['field' => $this->field->name]));
    }
}
