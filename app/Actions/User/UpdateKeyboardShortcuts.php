<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Models\User;

final readonly class UpdateKeyboardShortcuts
{
    public function execute(User $user, bool $enabled): void
    {
        $user->update(['single_key_shortcuts' => $enabled]);
    }
}
