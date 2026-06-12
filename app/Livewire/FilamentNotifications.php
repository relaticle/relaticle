<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Filament\Notifications\NotificationCollection;
use Filament\Livewire\Notifications;

/**
 * Swaps the notifications collection for the junk-tolerant subclass so the
 * dehydrated Livewire snapshot points hydration at it (the wireable synth
 * calls fromLivewire on whatever class the live property was).
 */
final class FilamentNotifications extends Notifications
{
    public function mount(): void
    {
        $this->notifications = new NotificationCollection;
        $this->pullNotificationsFromSession();
    }
}
