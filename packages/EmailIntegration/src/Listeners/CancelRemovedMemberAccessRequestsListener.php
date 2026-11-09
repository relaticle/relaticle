<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Listeners;

use App\Models\User;
use App\Models\Workspace;
use Laravel\Jetstream\Events\TeamMemberRemoved;
use Relaticle\EmailIntegration\Actions\DecideEmailAccessRequest;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

final readonly class CancelRemovedMemberAccessRequestsListener
{
    public function __construct(private DecideEmailAccessRequest $decide) {}

    public function handle(TeamMemberRemoved $event): void
    {
        if (! $event->user instanceof User || ! $event->team instanceof Workspace) {
            return;
        }

        EmailAccessRequest::query()
            ->with('owner')
            ->where('requester_id', $event->user->getKey())
            ->where('workspace_id', $event->team->getKey())
            ->open()
            ->get()
            ->each(fn (EmailAccessRequest $request): bool => $this->decide->execute($request, EmailAccessRequestStatus::CANCELLED, $event->user));
    }
}
