<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\Team;
use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\ProtectedRecipient;

final class PrivacyService
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

        // 3. Per-email share overrides the email's own tier (uses the loaded relation when
        // eager-loaded, so filtering a list of emails doesn't issue a query per row).
        $email->loadMissing('shares');
        $share = $email->shares->firstWhere('shared_with', $viewer->getKey());

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

        // Resolve the team explicitly (instead of $user->currentTeam, whose accessor
        // larastan types as never-null and which can auto-switch teams as a side
        // effect) so the null case — a user without a current team — is handled.
        $team = $user->current_team_id !== null ? Team::query()->find($user->current_team_id) : null;

        if ($team === null) {
            return EmailPrivacyTier::METADATA_ONLY;
        }

        return $team->default_email_sharing_tier ?? EmailPrivacyTier::METADATA_ONLY;
    }

    /**
     * Per-team protected recipient lists, memoized for the lifetime of this instance to
     * avoid two ProtectedRecipient queries per email when filtering a list of emails.
     *
     * @var array<string, array{emails: list<string>, domains: list<string>}>
     */
    private array $protectedCache = [];

    /**
     * Check whether any participant on this email matches a protected_recipients row.
     */
    private function isProtected(Email $email): bool
    {
        $email->loadMissing(['participants']);

        ['emails' => $protectedEmails, 'domains' => $protectedDomains] = $this->protectedRecipients($email->team_id);

        foreach ($email->participants as $participant) {
            $address = strtolower((string) $participant->email_address);
            $domain = explode('@', $address)[1] ?? '';

            if (in_array($address, $protectedEmails, true)) {
                return true;
            }

            if ($domain !== '' && in_array($domain, $protectedDomains, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{emails: list<string>, domains: list<string>}
     */
    private function protectedRecipients(string $teamId): array
    {
        if (isset($this->protectedCache[$teamId])) {
            return $this->protectedCache[$teamId];
        }

        $byType = ProtectedRecipient::query()
            ->where('team_id', $teamId)
            ->whereIn('type', ['email', 'domain'])
            ->get(['type', 'value']);

        return $this->protectedCache[$teamId] = [
            'emails' => array_values($byType->where('type', 'email')
                ->map(fn (ProtectedRecipient $r): string => strtolower((string) $r->value))
                ->all()),
            'domains' => array_values($byType->where('type', 'domain')
                ->map(fn (ProtectedRecipient $r): string => strtolower((string) $r->value))
                ->all()),
        ];
    }
}
