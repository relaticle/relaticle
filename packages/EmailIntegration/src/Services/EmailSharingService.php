<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailShare;

final readonly class EmailSharingService
{
    /**
     * Share a single email with a specific user at a given tier.
     */
    public function shareEmail(Email $email, User $sharedBy, User $sharedWith, EmailPrivacyTier $tier): EmailShare
    {
        return EmailShare::query()->updateOrCreate([
            'email_id' => $email->getKey(),
            'shared_with' => $sharedWith->getKey(),
        ], [
            'workspace_id' => $email->workspace_id,
            'shared_by' => $sharedBy->getKey(),
            'tier' => $tier->value,
        ]);
    }

    /**
     * Revoke a share.
     */
    public function revokeShare(Email $email, User $sharedWith): void
    {
        EmailShare::query()->where('email_id', $email->getKey())
            ->where('shared_with', $sharedWith->getKey())
            ->delete();
    }

    /**
     * Update an email's own privacy_tier.
     */
    public function setEmailTier(Email $email, EmailPrivacyTier $tier): void
    {
        $email->update([
            'privacy_tier' => $tier,
            'privacy_tier_customized' => true,
        ]);
    }
}
