<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Support\Auth\AuthenticationSession;
use App\Support\Auth\IdentityConfirmation;

final readonly class CancelAuthentication
{
    public function execute(): void
    {
        AuthenticationSession::clear();
        IdentityConfirmation::clearMfaPending();
        session()->forget('url.intended');
        session()->regenerateToken();
    }
}
