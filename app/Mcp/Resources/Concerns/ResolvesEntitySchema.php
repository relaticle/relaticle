<?php

declare(strict_types=1);

namespace App\Mcp\Resources\Concerns;

use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\CustomField;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

trait ResolvesEntitySchema
{
    /**
     * @return array<string, array{name: string, type: string, required: bool}>
     */
    protected function resolveCustomFields(User $user, string $entityType): array
    {
        $teamId = $user->currentTeam->getKey();
        $cacheKey = "custom_fields_schema_{$teamId}_{$entityType}";

        return Cache::remember($cacheKey, 60, function () use ($teamId, $entityType): array {
            $fields = CustomField::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $teamId)
                ->where('entity_type', $entityType)
                ->active()
                ->select('code', 'name', 'type', 'validation_rules')
                ->get();

            return $this->formatCustomFields($fields);
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function resolveFilterableFields(User $user, string $entityType): array
    {
        return (new CustomFieldFilterSchema)->build($user, $entityType);
    }

    /**
     * @param  Collection<int, CustomField>  $fields
     * @return array<string, array{name: string, type: string, required: bool}>
     */
    private function formatCustomFields(Collection $fields): array
    {
        $result = [];

        foreach ($fields as $field) {
            $required = ($field->validation_rules ?? collect())
                ->contains('name', 'required');

            $result[$field->code] = [
                'name' => $field->name,
                'type' => $field->type,
                'required' => $required,
            ];
        }

        return $result;
    }
}
