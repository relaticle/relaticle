<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\ProtectedRecipient;

final readonly class PrivacyService
{
    /**
     * Resolve the effective privacy tier this $viewer can see on $email.
     * Returns null if the email is completely hidden (protected recipient / private / internal).
     */
    public function effectiveTier(Email $email, User $viewer): ?EmailPrivacyTier
    {
        // Owner always gets full access
        if ($email->user_id === $viewer->getKey()) {
            return EmailPrivacyTier::FULL;
        }

        // 1. Protected recipient — hard hidden for everyone except the owner
        if ($this->isProtected($email)) {
            return null;
        }

        // 2. Internal emails are hidden (all participants are workspace members)
        if ($email->is_internal) {
            return null;
        }

        // 3. Per-email share overrides the email's own tier
        $share = $email->shares()
            ->where('shared_with', $viewer->getKey())
            ->first();

        if ($share) {
            return EmailPrivacyTier::from($share->tier);
        }

        // 4. Email's own tier
        $tier = $email->privacy_tier;

        if ($tier === EmailPrivacyTier::PRIVATE) {
            return null;
        }

        return $tier;
    }

    /**
     * Resolve the default tier to stamp on a newly synced email.
     * User preference wins over workspace default.
     */
    public function defaultTierForUser(User $user): EmailPrivacyTier
    {
        if ($user->default_email_sharing_tier) {
            return $user->default_email_sharing_tier;
        }

        return $user->currentTeam->default_email_sharing_tier ?? EmailPrivacyTier::METADATA_ONLY;
    }

    /**
     * Check whether any participant on this email matches a protected_recipients row.
     */
    private function isProtected(Email $email): bool
    {
        $email->loadMissing(['participants']);

        $teamId = $email->team_id;

        $protectedEmails = ProtectedRecipient::query()->where('team_id', $teamId)
            ->where('type', 'email')
            ->pluck('value')
            ->map(fn (mixed $value): string => strtolower((string) $value));

        $protectedDomains = ProtectedRecipient::query()->where('team_id', $teamId)
            ->where('type', 'domain')
            ->pluck('value')
            ->map(fn (mixed $value): string => strtolower((string) $value));

        foreach ($email->participants as $participant) {
            $address = strtolower((string) $participant->email_address);
            $domain = explode('@', $address)[1] ?? '';

            if ($protectedEmails->contains($address)) {
                return true;
            }

            if ($protectedDomains->contains($domain)) {
                return true;
            }
        }

        return false;
    }
}
