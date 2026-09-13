<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Chat\SeedWorkspaceCreditBalance;
use App\Events\WorkspaceCreated;

final readonly class SeedWorkspaceCreditBalanceListener
{
    public function __construct(private SeedWorkspaceCreditBalance $action) {}

    public function handle(WorkspaceCreated $event): void
    {
        $this->action->execute($event->workspace);
    }
}
