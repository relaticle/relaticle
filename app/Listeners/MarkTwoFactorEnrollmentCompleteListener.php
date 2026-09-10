<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\Auth\AuthenticationSession;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;

final class MarkTwoFactorEnrollmentCompleteListener
{
    public function handle(TwoFactorAuthenticationConfirmed $event): void
    {
        AuthenticationSession::markComplete($event->user);
    }
}
