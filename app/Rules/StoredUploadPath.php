<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\MediaCollection;
use App\Support\Media\MediaPaths;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Relaticle\CustomFields\Models\CustomField;

final readonly class StoredUploadPath implements ValidationRule
{
    public function __construct(
        private string $teamId,
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
            && (string) $media->model_id === (string) $this->entityId
            && $media->collection_name === MediaCollection::forCustomField($this->field->code);

        if ($ownsCurrentValue) {
            return;
        }

        $fail(__('validation.custom_field.upload_path', ['field' => $this->field->name]));
    }
}
