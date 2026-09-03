<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\Team;
use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;

final readonly class PrivacyService
{
    public function __construct(private EmailVisibilityService $visibility) {}

    /**
     * Resolve the effective privacy tier this $viewer can see on $email.
     * Returns null if the email is completely hidden (visibility rules / private / internal).
     */
    public function effectiveTier(Email $email, User $viewer): ?EmailPrivacyTier
    {
        if ($email->user_id === $viewer->getKey()) {
            if ($this->visibility->isHiddenFromOwner($email)) {
                return null;
            }

            return EmailPrivacyTier::FULL;
        }

        if ($this->visibility->isHiddenFromTeammate($email)) {
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
        // effect) so the null case, a user without a current team, is handled.
        $team = $user->current_team_id !== null ? Team::query()->find($user->current_team_id) : null;

        if ($team === null) {
            return EmailPrivacyTier::METADATA_ONLY;
        }

        return $team->default_email_sharing_tier ?? EmailPrivacyTier::METADATA_ONLY;
    }
}
