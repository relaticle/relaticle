<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\PrivacyService;

final class EmailPolicy
{
    public function __construct(private readonly PrivacyService $privacyService) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    /** Can the viewer see this email exists at all? */
    public function view(User $user, Email $email): bool
    {
        return $this->privacyService->effectiveTier($email, $user) !== null;
    }

    /** Can the viewer see the subject line? */
    public function viewSubject(User $user, Email $email): bool
    {
        $tier = $this->privacyService->effectiveTier($email, $user);

        return $tier !== null && $tier !== EmailPrivacyTier::METADATA_ONLY;
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

        return $tier !== null && $tier !== EmailPrivacyTier::FULL;
    }
}
