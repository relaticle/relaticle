<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\User;
use App\Rules\ValidCustomFields;

trait ValidatesCustomFields
{
    abstract public function customFieldEntityType(): string;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function customFieldRules(): array
    {
        $teamId = $this->resolveTeamId();

        if ($teamId === null) {
            return [];
        }

        $rule = new ValidCustomFields($teamId, $this->customFieldEntityType(), $this->isUpdateRequest());

        return array_merge(
            ['custom_fields' => ['nullable', 'array', $rule]],
            $rule->toRules($this->input('custom_fields')),
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
