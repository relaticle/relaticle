<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Notifications\EmailAccessRespondedNotification;
use Relaticle\EmailIntegration\Services\EmailSharingService;

final readonly class ApproveEmailAccessRequestAction
{
    public function __construct(
        private DecideEmailAccessRequest $decide,
        private EmailSharingService $sharingService,
    ) {}

    public function execute(EmailAccessRequest $accessRequest, User $actor): bool
    {
        abort_unless($accessRequest->owner_id === $actor->getKey(), 403);

        if (! $accessRequest->isOpen()) {
            return false;
        }

        $email = $accessRequest->email;
        $owner = $accessRequest->owner;
        $requester = $accessRequest->requester;

        if ($email === null || $owner === null || $requester === null) {
            return false;
        }

        // Don't grant a share to a requester who is no longer in the email's workspace.
        abort_unless($requester->belongsToWorkspaceId($email->workspace_id), 403);

        $tier = EmailPrivacyTier::from($accessRequest->tier_requested);

        $approved = DB::transaction(function () use ($accessRequest, $actor, $email, $owner, $requester, $tier): bool {
            if (! $this->decide->execute($accessRequest, EmailAccessRequestStatus::APPROVED, $actor)) {
                return false;
            }

            $this->sharingService->shareEmail($email, $owner, $requester, $tier);

            return true;
        });

        if (! $approved) {
            return false;
        }

        $requester->notify(new EmailAccessRespondedNotification($accessRequest));

        return true;
    }
}
