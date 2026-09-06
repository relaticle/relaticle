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

    /**
     * Tenant isolation for every instance ability. Return null so viewAny (class
     * argument) and same-team viewers still reach the method.
     */
    public function before(User $user, string $ability, mixed $email = null): ?bool
    {
        if (! $email instanceof Email) {
            return null;
        }

        return $user->belongsToTeamId($email->team_id) ? null : false;
    }

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
        return $this->privacyService->effectiveTier($email, $user) instanceof EmailPrivacyTier;
    }

    /** Can the viewer see the subject line? */
    public function viewSubject(User $user, Email $email): bool
    {
        $tier = $this->privacyService->effectiveTier($email, $user);

        return $tier instanceof EmailPrivacyTier && $tier !== EmailPrivacyTier::METADATA_ONLY;
    }

    /** Can the viewer see the body and attachments? */
    public function viewBody(User $user, Email $email): bool
    {
        $tier = $this->privacyService->effectiveTier($email, $user);

        return $tier === EmailPrivacyTier::FULL;
    }

    /** Can the viewer change sharing settings? Owner only. */
    public function share(User $user, Email $email): bool
    {
        return $email->user_id === $user->getKey();
    }

    /** Can the viewer request access? Non-owners who can see metadata but not body. */
    public function requestAccess(User $user, Email $email): bool
    {
        if ($email->user_id === $user->getKey()) {
            return false;
        }

        $tier = $this->privacyService->effectiveTier($email, $user);

        return $tier instanceof EmailPrivacyTier && $tier !== EmailPrivacyTier::FULL;
    }
}
