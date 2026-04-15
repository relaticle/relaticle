<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Notifications\EmailAccessRespondedNotification;

final readonly class DenyEmailAccessRequestAction
{
    public function execute(EmailAccessRequest $accessRequest): void
    {
        if ($accessRequest->status !== EmailAccessRequestStatus::PENDING) {
            return;
        }

        $requester = $accessRequest->requester;

        if ($requester === null) {
            return;
        }

        $accessRequest->update(['status' => EmailAccessRequestStatus::DENIED]);

        $requester->notify(new EmailAccessRespondedNotification($accessRequest));
    }
}
