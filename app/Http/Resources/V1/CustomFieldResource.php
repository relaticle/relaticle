<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\CustomField;
use App\Models\CustomFieldOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Support\Carbon;
use Relaticle\CustomFields\Services\ValidationService;

/**
 * @mixin CustomField
 *
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class CustomFieldResource extends JsonApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        $validationService = resolve(ValidationService::class);

        return [
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'entity_type' => $this->entity_type,
            'required' => $validationService->isRequired($this->resource),
            'options' => $this->whenLoaded('options', fn () => $this->options->map(fn (CustomFieldOption $option): array => [
                'label' => $option->name,
                'value' => $option->id,
            ])->all()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
