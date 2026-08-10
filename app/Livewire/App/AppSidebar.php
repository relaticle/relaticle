<?php

declare(strict_types=1);

namespace App\Livewire\App;

use Filament\Livewire\Sidebar;
use Illuminate\Contracts\View\View;

/**
 * The app panel's sidebar. Identical to Filament's, except global search and
 * the notifications trigger share one row above the navigation instead of
 * sitting in separate slots (search above the nav, notifications in the footer).
 */
final class AppSidebar extends Sidebar
{
    public function render(): View
    {
        return view('filament.app.sidebar');
    }
}
