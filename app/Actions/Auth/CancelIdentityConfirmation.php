<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use App\Support\Auth\IdentityConfirmation;

final readonly class CancelIdentityConfirmation
{
    public function execute(User $user): void
    {
        $grantId = IdentityConfirmation::mfaPendingOperationGrantId($user);

        if ($grantId !== null) {
            AuthenticationSession::cancelOperation($grantId);
        }

        IdentityConfirmation::clearMfaPending();
        session()->forget('url.intended');
    }
}
