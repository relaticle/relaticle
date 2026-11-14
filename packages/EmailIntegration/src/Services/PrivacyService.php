<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\User;
use App\Models\Workspace;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailShare;

final readonly class PrivacyService
{
    public function __construct(
        private EmailVisibilityService $visibility,
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

        // Per-email share overrides the email's own tier (uses the loaded relation when
        // eager-loaded, so filtering a list of emails doesn't issue a query per row).
        $share = $this->shareForViewer($email, $viewer);

        if ($share instanceof EmailShare) {
            $tier = EmailPrivacyTier::from($share->tier);

            if ($tier === EmailPrivacyTier::PRIVATE) {
                return null;
            }

            return $tier;
        }

        if (! $email->isLinkedToRecord()) {
            return null;
        }

        $tier = $email->privacy_tier;

        if ($tier === EmailPrivacyTier::PRIVATE) {
            return null;
        }

        return $tier;
    }

    public function tierForMailbox(ConnectedAccount $mailbox): EmailPrivacyTier
    {
        if ($mailbox->sharing_tier instanceof EmailPrivacyTier) {
            return $mailbox->sharing_tier;
        }

        $default = $this->workspaceSharingTier($mailbox->loadMissing('workspace')->workspace);

        return ! $mailbox->hasAnsweredSharing() && $default->openness() > EmailPrivacyTier::METADATA_ONLY->openness()
            ? EmailPrivacyTier::METADATA_ONLY
            : $default;
    }

    public function workspaceSharingTier(Workspace $workspace): EmailPrivacyTier
    {
        return $workspace->default_email_sharing_tier ?? EmailPrivacyTier::METADATA_ONLY;
    }

    private function shareForViewer(Email $email, User $viewer): ?EmailShare
    {
        $email->loadMissing('shares');

        return $email->shares->firstWhere('shared_with', $viewer->getKey());
    }
}
