<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

final readonly class CancelEmailAccessRequestAction
{
    public function execute(EmailAccessRequest $accessRequest): void
    {
        if ($accessRequest->status !== EmailAccessRequestStatus::PENDING) {
            return;
        }

        $accessRequest->delete();
    }
}
