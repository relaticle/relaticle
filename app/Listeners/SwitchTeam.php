<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Filament\Events\TenantSet;
use Laravel\Jetstream\Features;

final readonly class SwitchTeam
{
    /**
     * Handle the event.
     */
    public function handle(TenantSet $event): void
    {
        if (! Features::hasTeamFeatures()) {
            return;
        }

        $user = $event->getUser();
        $team = $event->getTenant();

        if ($user instanceof User) {
            $user->switchTeam($team);
        }
    }
}
