<?php

declare(strict_types=1);

namespace App\Livewire\App;

use App\Actions\Favorites\RemoveFavoriteRecord;
use App\Livewire\BaseLivewireComponent;
use App\Models\Company;
use App\Models\FavoriteRecord;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Favorites\FavoritesQuery;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;

/**
 * Listens to nothing, like ChatSidebarNav: nested in Filament's sidebar, its own
 * re-render never reaches the DOM, so every change dispatches `refresh-sidebar`.
 */
final class FavoritesSidebarNav extends BaseLivewireComponent
{
    public function removeFavorite(string $favoriteRecordId, RemoveFavoriteRecord $removeFavoriteRecord): void
    {
        $user = Filament::auth()->user();
        $workspace = Filament::getTenant();

        if (! $user instanceof User || ! $workspace instanceof Workspace) {
            return;
        }

        $record = FavoriteRecord::query()
            ->ownedBy($user, $workspace->getKey())
            ->find($favoriteRecordId)
            ?->record()
            ->withTrashed()
            ->first();

        if (! $record instanceof Company && ! $record instanceof People && ! $record instanceof Opportunity) {
            return;
        }

        $removeFavoriteRecord->execute($user, $record);

        $this->dispatch('refresh-sidebar');
    }

    public function render(): View
    {
        $user = Filament::auth()->user();
        $workspace = Filament::getTenant();

        return view('livewire.app.favorites-sidebar-nav', [
            'items' => $user instanceof User && $workspace instanceof Workspace
                ? new FavoritesQuery()->tree($user, $workspace->getKey())
                : collect(),
            'workspace' => $workspace,
        ]);
    }
}
