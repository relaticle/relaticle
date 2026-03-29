<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Http\Resources\V1\Concerns\FormatsCustomFields;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

/**
 * @mixin Company
 */
final class CompanyResource extends JsonApiResource
{
    use FormatsCustomFields;

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        return [
            'name' => $this->name,
            'creation_source' => $this->creation_source,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'custom_fields' => $this->formatCustomFields($this->resource),
            'people_count' => $this->whenHas('people_count'),
            'opportunities_count' => $this->whenHas('opportunities_count'),
            'tasks_count' => $this->whenHas('tasks_count'),
            'notes_count' => $this->whenHas('notes_count'),
        ];
    }

    /**
     * @return array<string, class-string<JsonApiResource>>
     */
    public function toRelationships(Request $request): array
    {
        return [
            'creator' => UserResource::class,
            'accountOwner' => UserResource::class,
            'people' => PeopleResource::class,
            'opportunities' => OpportunityResource::class,
        ];
    }
}
