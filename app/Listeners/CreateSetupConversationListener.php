<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Onboarding\CreateSetupConversation;
use App\Events\WorkspaceCreated;

final readonly class CreateSetupConversationListener
{
    public function __construct(private CreateSetupConversation $action) {}

    public function handle(WorkspaceCreated $event): void
    {
        $this->action->execute($event->workspace);
    }
}
