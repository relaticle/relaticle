<?php

declare(strict_types=1);

namespace App\Livewire\App;

use App\Actions\Favorites\ArrangeFavorites;
use App\Actions\Favorites\CreateFavoriteFolder;
use App\Actions\Favorites\DeleteFavoriteFolder;
use App\Actions\Favorites\RemoveFavoriteRecord;
use App\Actions\Favorites\RenameFavoriteFolder;
use App\Livewire\BaseLivewireComponent;
use App\Models\Company;
use App\Models\FavoriteFolder;
use App\Models\FavoriteRecord;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Favorites\FavoritesQuery;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
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
        $this->dispatch('favorites-updated');
    }

    /**
     * @param  array<mixed>  $tree
     */
    public function arrange(array $tree, ArrangeFavorites $arrangeFavorites): void
    {
        $workspace = Filament::getTenant();
        abort_unless($workspace instanceof Workspace, 403);

        $arrangeFavorites->execute($this->authUser(), $workspace, $tree);

        $this->dispatch('refresh-sidebar');
        $this->dispatch('favorites-updated');
    }

    public function createFolderAction(): Action
    {
        return Action::make('createFolder')
            ->label(__('favorites.actions.new_folder'))
            ->modalHeading(__('favorites.actions.new_folder'))
            ->modalWidth('sm')
            ->schema($this->folderNameSchema())
            ->action(function (array $data, CreateFavoriteFolder $createFavoriteFolder): void {
                $workspace = Filament::getTenant();

                if (! $workspace instanceof Workspace) {
                    return;
                }

                $createFavoriteFolder->execute($this->authUser(), $workspace, $data['name']);

                $this->dispatch('refresh-sidebar');
                $this->dispatch('favorites-updated');
            });
    }

    public function renameFolderAction(): Action
    {
        return Action::make('renameFolder')
            ->label(__('favorites.folders.rename'))
            ->modalHeading(__('favorites.folders.rename'))
            ->modalWidth('sm')
            ->schema($this->folderNameSchema())
            ->fillForm(fn (array $arguments): array => ['name' => $this->folderFromArguments($arguments)->name])
            ->action(function (array $arguments, array $data, RenameFavoriteFolder $renameFavoriteFolder): void {
                $renameFavoriteFolder->execute($this->authUser(), $this->folderFromArguments($arguments), $data['name']);

                $this->dispatch('refresh-sidebar');
                $this->dispatch('favorites-updated');
            });
    }

    public function deleteFolderAction(): Action
    {
        return Action::make('deleteFolder')
            ->label(__('favorites.folders.delete'))
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('favorites.folders.delete'))
            ->modalDescription(__('favorites.folders.delete_body'))
            ->action(function (array $arguments, DeleteFavoriteFolder $deleteFavoriteFolder): void {
                $deleteFavoriteFolder->execute($this->authUser(), $this->folderFromArguments($arguments));

                $this->dispatch('refresh-sidebar');
                $this->dispatch('favorites-updated');
            });
    }

    /**
     * @return array<int, TextInput>
     */
    private function folderNameSchema(): array
    {
        return [
            TextInput::make('name')
                ->label(__('favorites.folders.name'))
                ->required()
                ->maxLength(FavoriteFolder::NAME_MAX_LENGTH)
                ->autofocus()
                ->dehydrateStateUsing(fn (?string $state): string => trim((string) $state)),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function folderFromArguments(array $arguments): FavoriteFolder
    {
        $workspace = Filament::getTenant();
        abort_unless($workspace instanceof Workspace, 403);

        return FavoriteFolder::query()
            ->ownedBy($this->authUser(), $workspace->getKey())
            ->findOrFail((string) ($arguments['folder'] ?? ''));
    }

    public function render(): View
    {
        $user = Filament::auth()->user();
        $workspace = Filament::getTenant();

        $items = $user instanceof User && $workspace instanceof Workspace
            ? new FavoritesQuery()->tree($user, $workspace->getKey())
            : collect();

        return view('livewire.app.favorites-sidebar-nav', [
            'items' => $items,
            'hasFavorites' => $items->isNotEmpty(),
            'workspace' => $workspace,
        ]);
    }
}
