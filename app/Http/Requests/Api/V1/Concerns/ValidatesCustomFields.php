<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\User;
use App\Services\CustomFieldValidationService;

/**
 * Provides custom field validation rules for API FormRequests.
 *
 * Delegates to CustomFieldValidationService for field resolution and rule generation.
 *
 * For STORE requests: validates submitted fields + flags missing required fields.
 * For UPDATE requests: only validates the custom fields actually submitted.
 */
trait ValidatesCustomFields
{
    abstract public function customFieldEntityType(): string;

    /**
     * Get the route parameter name for the entity (used for unique-value validation on updates).
     */
    public function routeParameterName(): string
    {
        return 'id';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function customFieldRules(): array
    {
        $teamId = $this->resolveTeamId();

        if ($teamId === null) {
            return [];
        }

        return CustomFieldValidationService::rules(
            tenantId: $teamId,
            entityType: $this->customFieldEntityType(),
            submittedFields: $this->input('custom_fields'),
            isUpdate: $this->isUpdateRequest(),
        );
    }

    public function isUpdateRequest(): bool
    {
        return in_array($this->method(), ['PUT', 'PATCH'], true);
    }

    private function resolveTeamId(): ?string
    {
        /** @var User|null $user */
        $user = $this->user();

        return $user?->currentTeam?->getKey();
    }
}
