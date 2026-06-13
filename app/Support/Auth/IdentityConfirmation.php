<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;

/**
 * Uniform identity-confirmation gate, mirroring Laravel's RequirePassword model.
 *
 * A passkey ceremony (vendor passkey.confirm endpoint) or a password entry both
 * refresh auth.password_confirmed_at; callers gate on freshness. A user who has
 * neither a password nor a passkey cannot prove more than their active session,
 * so the gate treats them as already satisfied.
 */
final class IdentityConfirmation
{
    public static function satisfied(User $user): bool
    {
        if (! $user->hasPassword() && ! $user->hasPasskey()) {
            return true;
        }

        return self::confirmedRecently();
    }

    public static function confirmedRecently(): bool
    {
        $confirmedAt = (int) session('auth.password_confirmed_at', 0);

        return (time() - $confirmedAt) < Config::integer('auth.password_timeout', 10800);
    }

    public static function markConfirmed(): void
    {
        session()->put('auth.password_confirmed_at', time());
    }

    public static function verifyPassword(User $user, string $password): bool
    {
        return Hash::check($password, (string) $user->password);
    }
}
