<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Models\User;

final readonly class UpdateUserName
{
    public function execute(User $user, string $name): void
    {
        $user->update(['name' => $name]);
    }
}
