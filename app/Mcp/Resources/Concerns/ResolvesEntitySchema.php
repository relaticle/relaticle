<?php

declare(strict_types=1);

namespace App\Mcp\Resources\Concerns;

use App\Models\CustomField;
use App\Models\User;
use Illuminate\Support\Collection;

trait ResolvesEntitySchema
{
    /**
     * @return array<string, array{name: string, type: string, required: bool}>
     */
    protected function resolveCustomFields(User $user, string $entityType): array
    {
        $fields = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $user->currentTeam->getKey())
            ->where('entity_type', $entityType)
            ->where('active', true)
            ->select('code', 'name', 'type', 'validation_rules')
            ->get();

        return $this->formatCustomFields($fields);
    }

    /**
     * @param  Collection<int, CustomField>  $fields
     * @return array<string, array{name: string, type: string, required: bool}>
     */
    private function formatCustomFields(Collection $fields): array
    {
        $result = [];

        foreach ($fields as $field) {
            $required = $field->validation_rules
                ->toCollection()
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
