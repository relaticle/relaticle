<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Models\User;
use App\Services\CustomFieldValidationService;

/**
 * Provides custom field validation rules for MCP tools.
 *
 * Delegates to CustomFieldValidationService for field resolution and rule generation,
 * ensuring consistent validation across all entry points.
 */
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

        return CustomFieldValidationService::rules(
            tenantId: $teamId,
            entityType: $entityType,
            submittedFields: $submittedFields,
            isUpdate: $isUpdate,
        );
    }
}
