<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Filament\Events\TenantSet;

final readonly class SwitchWorkspace
{
    /**
     * Handle the event.
     */
    public function handle(TenantSet $event): void
    {
        $user = $event->getUser();
        $workspace = $event->getTenant();

        if ($user instanceof User) {
            $user->switchWorkspace($workspace);
        }
    }
}
