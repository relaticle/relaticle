<?php

declare(strict_types=1);

namespace App\Livewire\App;

use Filament\Livewire\DatabaseNotifications;
use Illuminate\Contracts\View\View;

/**
 * Notifications with a compact trigger sized for the sidebar's search row.
 * Filament picks between a topbar icon button and a full-width sidebar button
 * from the configured position; neither fits beside the search field.
 */
final class AppDatabaseNotifications extends DatabaseNotifications
{
    public function getTrigger(): View
    {
        return view('filament.app.notifications-trigger');
    }
}
