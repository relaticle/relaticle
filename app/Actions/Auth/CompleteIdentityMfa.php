<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use App\Support\Auth\IdentityConfirmation;
use Illuminate\Validation\ValidationException;

final readonly class CompleteIdentityMfa
{
    public function execute(User $user, ?string $code, ?string $recoveryCode): void
    {
        if (! IdentityConfirmation::mfaPendingFor($user)) {
            IdentityConfirmation::clearMfaPending();

            throw ValidationException::withMessages([
                'code' => [__('auth.mfa.expired')],
            ]);
        }

        $boundGrantId = IdentityConfirmation::mfaPendingOperationGrantId($user);

        IdentityConfirmation::requireMfaProof($user, $code, $recoveryCode);

        IdentityConfirmation::clearMfaPending();

        $operation = AuthenticationSession::pendingOperation();

        // Trust the current session slot only if it is still the exact grant
        // bound when the MFA-pending marker was set. A second modal opened
        // elsewhere while this code was outstanding overwrites the single slot;
        // that unrelated grant must never be marked proven by this MFA proof.
        $matchesBoundGrant = $boundGrantId !== null
            && $operation !== []
            && $operation['id'] === $boundGrantId
            && $operation['user_id'] === (string) $user->getAuthIdentifier();

        IdentityConfirmation::confirmOperation($user, $matchesBoundGrant
            ? ['operation' => $operation['operation'], 'target_id' => $operation['target_id']]
            : null);
    }
}
