<?php

declare(strict_types=1);

namespace App\Actions\Passkeys;

use App\Models\User;
use Laravel\Passkeys\Passkey;

final readonly class RenamePasskey
{
    public function execute(User $user, Passkey $passkey, string $name): Passkey
    {
        abort_unless($passkey->user_id === $user->getKey(), 403);

        $passkey->update(['name' => $name]);

        return $passkey;
    }
}
