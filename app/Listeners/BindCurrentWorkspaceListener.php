<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Filament\Events\TenantSet;
use Illuminate\Auth\Events\Authenticated;

final readonly class BindCurrentWorkspaceListener
{
    public function __construct(private CurrentWorkspace $currentWorkspace) {}

    public function handle(Authenticated|TenantSet $event): void
    {
        if ($event instanceof Authenticated && ! $event->user instanceof User) {
            return;
        }

        $workspace = $event instanceof TenantSet ? $event->getTenant() : $event->user->currentWorkspace;

        $workspace instanceof Workspace
            ? $this->currentWorkspace->set($workspace)
            : $this->currentWorkspace->forget();
    }
}
