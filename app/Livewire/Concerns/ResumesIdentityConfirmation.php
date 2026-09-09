<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use Filament\Facades\Filament;

/**
 * Re-opens the action a provider confirmation was started for. Livewire calls
 * mount hooks named after the trait, so every component extending
 * BaseLivewireComponent picks this up without wiring it into its own mount().
 */
trait ResumesIdentityConfirmation
{
    public function mountResumesIdentityConfirmation(): void
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $resume = AuthenticationSession::pullResumableAction($user, $this->getName());

        if ($resume === null) {
            return;
        }

        $this->mountAction($resume['action'], $resume['arguments']);
    }
}
