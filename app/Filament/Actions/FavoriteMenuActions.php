<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Favorites\AddFavoriteRecord;
use App\Actions\Favorites\MoveFavoriteRecord;
use App\Actions\Favorites\RemoveFavoriteRecord;
use App\Models\Company;
use App\Models\FavoriteFolder;
use App\Models\FavoriteRecord;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Livewire\Component;

final class FavoriteMenuActions
{
    public static function group(Company|People|Opportunity $record): ActionGroup
    {
        $folders = FavoriteFolder::query()->ownedBy(self::member(), $record->workspace_id)->orderBy('position')->get();
        $isFavorite = fn (): bool => self::favorite($record) instanceof FavoriteRecord;

        return ActionGroup::make([
            Action::make('addFavorite')
                ->label(__('favorites.actions.add'))
                ->icon('lucide-star')
                ->visible(fn (): bool => $folders->isEmpty() && ! $isFavorite())
                ->action(function (Component $livewire, AddFavoriteRecord $addFavoriteRecord) use ($record): void {
                    self::add($livewire, $addFavoriteRecord, $record, null);
                }),
            self::folderPicker($folders, 'addFavoriteTo', __('favorites.actions.add'), fn (): bool => ! $isFavorite(), null, function (Component $livewire, ?FavoriteFolder $folder) use ($record): void {
                self::add($livewire, resolve(AddFavoriteRecord::class), $record, $folder);
            }),
            self::folderPicker($folders, 'moveFavoriteTo', __('favorites.actions.move'), $isFavorite, fn (): ?string => self::favorite($record)?->favorite_folder_id, function (Component $livewire, ?FavoriteFolder $folder) use ($record): void {
                resolve(MoveFavoriteRecord::class)->execute(self::member(), $record, $folder);
                $livewire->dispatch('refresh-sidebar');
            }),
            Action::make('removeFavorite')
                ->label(__('favorites.actions.remove'))
                ->icon('lucide-star-off')
                ->visible($isFavorite)
                ->action(function (Component $livewire, RemoveFavoriteRecord $removeFavoriteRecord) use ($record): void {
                    $removeFavoriteRecord->execute(self::member(), $record);
                    $livewire->dispatch('refresh-sidebar');
                }),
        ])->dropdown(false);
    }

    private static function add(Component $livewire, AddFavoriteRecord $addFavoriteRecord, Company|People|Opportunity $record, ?FavoriteFolder $folder): void
    {
        $addFavoriteRecord->execute(self::member(), $record, $folder);
        $livewire->dispatch('refresh-sidebar');
    }

    /**
     * @param  Collection<int, FavoriteFolder>  $folders
     * @param  Closure(): bool  $visible
     * @param  (Closure(): ?string)|null  $currentFolderId
     * @param  Closure(Component, ?FavoriteFolder): void  $choose
     */
    private static function folderPicker(Collection $folders, string $name, string $label, Closure $visible, ?Closure $currentFolderId, Closure $choose): ActionGroup
    {
        $choice = fn (?FavoriteFolder $folder): Action => self::folderChoice($name, $folder, $currentFolderId, $choose);

        return ActionGroup::make([
            ActionGroup::make([$choice(null)])->dropdown(false),
            ActionGroup::make($folders->map($choice)->all())->dropdown(false),
        ])
            ->label($label)
            ->icon('lucide-star')
            ->dropdownPlacement('right-start')
            ->dropdownTeleport()
            ->visible(fn (): bool => $folders->isNotEmpty() && $visible());
    }

    /**
     * @param  (Closure(): ?string)|null  $currentFolderId
     * @param  Closure(Component, ?FavoriteFolder): void  $choose
     */
    private static function folderChoice(string $name, ?FavoriteFolder $folder, ?Closure $currentFolderId, Closure $choose): Action
    {
        $label = $folder instanceof FavoriteFolder ? e($folder->name) : e(__('favorites.actions.no_folder'));

        return Action::make($name.($folder?->getKey() ?? 'Top'))
            ->label(fn (): HtmlString => new HtmlString(
                $currentFolderId instanceof Closure && $currentFolderId() === $folder?->getKey()
                    ? '<span class="flex w-full items-center gap-2"><span class="truncate">'.$label.'</span>'.svg('lucide-check', 'fi-menu-check')->toHtml().'</span>'
                    : $label,
            ))
            ->icon($folder instanceof FavoriteFolder ? 'lucide-folder' : 'lucide-square-slash')
            ->action(function (Component $livewire) use ($choose, $folder): void {
                $choose($livewire, $folder);
            });
    }

    private static function favorite(Company|People|Opportunity $record): ?FavoriteRecord
    {
        return FavoriteRecord::ownedFor(self::member(), $record);
    }

    private static function member(): User
    {
        /** @var User */
        return auth()->user();
    }
}
