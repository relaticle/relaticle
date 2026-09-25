<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final readonly class KeepsALastSuperAdministrator implements ValidationRule
{
    public function __construct(private ?SystemAdministrator $administrator) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->administrator?->role !== SystemAdministratorRole::SuperAdministrator) {
            return;
        }

        if ($value === SystemAdministratorRole::SuperAdministrator->value) {
            return;
        }

        if ($this->otherSuperAdministratorsExist()) {
            return;
        }

        $fail(__('The last Super Administrator cannot be given another role.'));
    }

    private function otherSuperAdministratorsExist(): bool
    {
        return SystemAdministrator::query()
            ->where('role', SystemAdministratorRole::SuperAdministrator)
            ->whereKeyNot($this->administrator->getKey())
            ->exists();
    }
}
