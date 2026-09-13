<?php

declare(strict_types=1);

namespace App\Support\Crm;

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Invitation-only access. With `crm.access.open_signup` off, a web request can
 * only create a user whose email has a pending team invitation. Artisan stays
 * allowed, so the first admin is created with `php artisan make:filament-user`.
 */
final readonly class SignupGate
{
    public function ensureAllowed(User $user): void
    {
        if (config('crm.access.open_signup')) {
            return;
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return;
        }

        $hasPendingInvitation = TeamInvitation::query()
            ->where('email', $user->email)
            ->get()
            ->contains(fn (TeamInvitation $invitation): bool => ! $invitation->isExpired());

        if ($hasPendingInvitation) {
            return;
        }

        throw ValidationException::withMessages([
            'data.email' => __('crm.signup_closed'),
        ]);
    }
}
