<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailShare;

final readonly class PrivacyService
{
    public function __construct(
        private EmailVisibilityService $visibility,
        private PreferredEmailCopyService $preferredCopies,
    ) {}

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

        if ($this->preferredCopies->viewerHasSyncedCopy($email, $viewer)) {
            return EmailPrivacyTier::FULL;
        }

        // Per-email share overrides the email's own tier (uses the loaded relation when
        // eager-loaded, so filtering a list of emails doesn't issue a query per row).
        $share = $this->shareForViewer($email, $viewer);

        if ($share instanceof EmailShare) {
            return EmailPrivacyTier::from($share->tier);
        }

        // Email's own tier
        $tier = $email->privacy_tier;

        if ($tier === EmailPrivacyTier::PRIVATE) {
            return null;
        }

        return $tier;
    }

    /**
     * Resolve the default tier to stamp on a newly created email.
     * User preference wins over workspace default.
     *
     * Pass the mailbox's workspace for background sync. `$user->current_team_id` is
     * only the owner's currently selected workspace, so using it for imports would
     * stamp another team's default onto this mailbox.
     */
    public function defaultTierForUser(User $user, ?Team $workspace = null): EmailPrivacyTier
    {
        if ($user->default_email_sharing_tier) {
            return $user->default_email_sharing_tier;
        }

        // Resolve the team explicitly (instead of $user->currentTeam, whose accessor
        // larastan types as never-null and which can auto-switch teams as a side
        // effect) so the null case, a user without a current team, is handled.
        $team = $workspace ?? ($user->current_team_id !== null ? Team::query()->find($user->current_team_id) : null);

        if ($team === null) {
            return EmailPrivacyTier::METADATA_ONLY;
        }

        return $team->default_email_sharing_tier ?? EmailPrivacyTier::METADATA_ONLY;
    }

    private function shareForViewer(Email $email, User $viewer): ?EmailShare
    {
        $email->loadMissing('shares');
        $direct = $email->shares->firstWhere('shared_with', $viewer->getKey());

        if ($direct instanceof EmailShare) {
            return $direct;
        }

        if (blank($email->rfc_message_id)) {
            return null;
        }

        return EmailShare::query()
            ->where('team_id', $email->team_id)
            ->where('shared_with', $viewer->getKey())
            ->whereHas('email', function (Builder $query) use ($email): void {
                $query
                    ->where('team_id', $email->team_id)
                    ->where('rfc_message_id', $email->rfc_message_id);
            })
            ->first();
    }
}
