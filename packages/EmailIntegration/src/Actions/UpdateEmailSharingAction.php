<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\EmailSharingService;

final readonly class UpdateEmailSharingAction
{
    public function __construct(private EmailSharingService $sharingService) {}

    /**
     * @param  array<int, array{shared_with: string|int, tier: string|EmailPrivacyTier}>  $shares
     */
    public function execute(Email $email, User $sharer, EmailPrivacyTier $tier, array $shares): void
    {
        // Defense in depth: only the email owner may change its sharing, regardless of caller.
        abort_unless($email->user_id === $sharer->getKey(), 403);

        $this->sharingService->setEmailTier($email, $tier);

        $email->shares()->where('shared_by', $sharer->getKey())->delete();

        foreach ($shares as $share) {
            $sharedWithUser = User::query()
                ->inTeam($sharer->current_team_id)
                ->whereKey($share['shared_with'])
                ->first();

            abort_if($sharedWithUser === null, 403);

            $tierForShare = $share['tier'] instanceof EmailPrivacyTier
                ? $share['tier']
                : EmailPrivacyTier::from($share['tier']);

            $this->sharingService->shareEmail($email, $sharer, $sharedWithUser, $tierForShare);
        }
    }
}
