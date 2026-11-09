<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Notifications\EmailAccessRespondedNotification;

final readonly class DenyEmailAccessRequestAction
{
    public function __construct(private DecideEmailAccessRequest $decide) {}

    public function execute(EmailAccessRequest $accessRequest, User $actor): bool
    {
        abort_unless($accessRequest->owner_id === $actor->getKey(), 403);

        if (! $accessRequest->isOpen()) {
            return false;
        }

        $requester = $accessRequest->requester;

        if ($requester === null) {
            return false;
        }

        $denied = $this->decide->execute($accessRequest, EmailAccessRequestStatus::DENIED, $actor);

        if (! $denied) {
            return false;
        }

        $requester->notify(new EmailAccessRespondedNotification($accessRequest));

        return true;
    }
}
