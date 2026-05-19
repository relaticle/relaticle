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
        $this->sharingService->setEmailTier($email, $tier);

        $email->shares()->where('shared_by', $sharer->getKey())->delete();

        foreach ($shares as $share) {
            /** @var User $sharedWithUser */
            $sharedWithUser = User::query()->findOrFail((string) $share['shared_with']);

            $tierForShare = $share['tier'] instanceof EmailPrivacyTier
                ? $share['tier']
                : EmailPrivacyTier::from($share['tier']);

            $this->sharingService->shareEmail($email, $sharer, $sharedWithUser, $tierForShare);
        }
    }
}
