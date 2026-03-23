<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Models\User;
use App\Rules\ValidCustomFields;

trait ValidatesCustomFields
{
    /**
     * @param  array<string, mixed>|null  $submittedFields
     * @return array<string, array<int, mixed>>
     */
    protected function customFieldValidationRules(
        User $user,
        string $entityType,
        ?array $submittedFields = null,
        bool $isUpdate = false,
    ): array {
        $teamId = $user->currentTeam?->getKey();

        if ($teamId === null) {
            return [];
        }

        $rule = new ValidCustomFields($teamId, $entityType, $isUpdate);

        return array_merge(
            ['custom_fields' => ['sometimes', 'array', $rule]],
            $rule->toRules($submittedFields),
        );
    }
}
