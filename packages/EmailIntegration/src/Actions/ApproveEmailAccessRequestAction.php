<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Notifications\EmailAccessRespondedNotification;
use Relaticle\EmailIntegration\Services\EmailSharingService;

final readonly class ApproveEmailAccessRequestAction
{
    public function __construct(private EmailSharingService $sharingService) {}

    public function execute(EmailAccessRequest $accessRequest, User $actor): void
    {
        abort_unless($accessRequest->owner_id === $actor->getKey(), 403);

        if ($accessRequest->status !== EmailAccessRequestStatus::PENDING) {
            return;
        }

        $email = $accessRequest->email;
        $owner = $accessRequest->owner;
        $requester = $accessRequest->requester;

        if ($email === null || $owner === null || $requester === null) {
            return;
        }

        $tier = EmailPrivacyTier::from($accessRequest->tier_requested);

        $this->sharingService->shareEmail($email, $owner, $requester, $tier);

        $accessRequest->update(['status' => EmailAccessRequestStatus::APPROVED]);

        $requester->notify(new EmailAccessRespondedNotification($accessRequest));
    }
}
