<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Policies;

use Relaticle\SystemAdmin\Models\SystemAdministrator;

final class ImportPolicy
{
    public function viewAny(): bool
    {
        return true;
    }

    public function view(): bool
    {
        return true;
    }

    public function create(SystemAdministrator $admin): bool
    {
        return false;
    }

    public function update(SystemAdministrator $admin): bool
    {
        return false;
    }

    public function delete(SystemAdministrator $admin): bool
    {
        return false;
    }

    public function deleteAny(SystemAdministrator $admin): bool
    {
        return false;
    }
}
