<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

final readonly class CancelEmailAccessRequestAction
{
    public function __construct(private DecideEmailAccessRequest $decide) {}

    public function execute(EmailAccessRequest $accessRequest, User $actor): bool
    {
        // Only the requester may cancel their own request.
        abort_unless($accessRequest->requester_id === $actor->getKey(), 403);

        if (! $accessRequest->isOpen()) {
            return false;
        }

        return $this->decide->execute($accessRequest, EmailAccessRequestStatus::CANCELLED, $actor);
    }
}
