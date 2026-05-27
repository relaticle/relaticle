<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Filament\Commands\MakeUserCommand;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final class MakeFilamentUserCommand extends MakeUserCommand
{
    #[\Override]
    protected function getUserData(): array
    {
        $data = parent::getUserData();

        if (is_a(self::getUserModel(), SystemAdministrator::class, allow_string: true)) {
            $data['role'] = SystemAdministratorRole::SuperAdministrator;
        }

        return $data;
    }

    #[\Override]
    protected function createUser(): Model&Authenticatable
    {
        $user = parent::createUser();

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return $user;
    }
}
