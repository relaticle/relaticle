<?php

declare(strict_types=1);

namespace App\Policies;

use App\Features\EmailIntegration;
use App\Models\User;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\PrivacyService;

final readonly class EmailPolicy
{
    public function __construct(private PrivacyService $privacyService) {}

    public function viewAny(User $user): bool
    {
        if (! Feature::active(EmailIntegration::class)) {
            return false;
        }

        return $user->hasVerifiedEmail() && $user->currentTeam !== null;
    }

    /** Can the viewer see this email exists at all? */
    public function view(User $user, Email $email): bool
    {
        if (! $user->belongsToTeamId($email->team_id)) {
            return false;
        }

        return $this->privacyService->effectiveTier($email, $user) instanceof EmailPrivacyTier;
    }

    /** Can the viewer see the subject line? */
    public function viewSubject(User $user, Email $email): bool
    {
        if (! $user->belongsToTeamId($email->team_id)) {
            return false;
        }

        $tier = $this->privacyService->effectiveTier($email, $user);

        return $tier instanceof EmailPrivacyTier && $tier !== EmailPrivacyTier::METADATA_ONLY;
    }

    /** Can the viewer see the body and attachments? */
    public function viewBody(User $user, Email $email): bool
    {
        if (! $user->belongsToTeamId($email->team_id)) {
            return false;
        }

        $tier = $this->privacyService->effectiveTier($email, $user);

        return $tier === EmailPrivacyTier::FULL;
    }

    /** Can the viewer change sharing settings? Owner only. */
    public function share(User $user, Email $email): bool
    {
        if (! $user->belongsToTeamId($email->team_id)) {
            return false;
        }

        return $email->user_id === $user->getKey();
    }

    /** Can the viewer request access? Non-owners who can see metadata but not body. */
    public function requestAccess(User $user, Email $email): bool
    {
        if (! $user->belongsToTeamId($email->team_id)) {
            return false;
        }

        if ($email->user_id === $user->getKey()) {
            return false;
        }

        $tier = $this->privacyService->effectiveTier($email, $user);

        return $tier instanceof EmailPrivacyTier && $tier !== EmailPrivacyTier::FULL;
    }
}
