<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Favorites\AddFavoriteRecord;
use App\Actions\Favorites\MoveFavorite;
use App\Actions\Favorites\RemoveFavoriteRecord;
use App\Models\Company;
use App\Models\Favorite;
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
        $folders = Favorite::query()->ownedBy(self::member(), $record->workspace_id)->folders()->orderBy('position')->get();
        $isFavorite = fn (): bool => self::favorite($record) instanceof Favorite;

        return ActionGroup::make([
            Action::make('addFavorite')
                ->label(__('favorites.actions.add'))
                ->icon('lucide-star')
                ->visible(fn (): bool => $folders->isEmpty() && ! $isFavorite())
                ->action(function (Component $livewire) use ($record): void {
                    self::add($livewire, $record, null);
                }),
            self::folderPicker($folders, 'addFavoriteTo', __('favorites.actions.add'), fn (): bool => ! $isFavorite(), null, function (Component $livewire, ?Favorite $folder) use ($record): void {
                self::add($livewire, $record, $folder);
            }),
            self::folderPicker($folders, 'moveFavoriteTo', __('favorites.actions.move'), $isFavorite, fn (): ?string => self::favorite($record)?->parent_id, function (Component $livewire, ?Favorite $folder) use ($record): void {
                resolve(MoveFavorite::class)->execute(self::member(), self::favorite($record) ?? abort(404), $folder);
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

    private static function add(Component $livewire, Company|People|Opportunity $record, ?Favorite $folder): void
    {
        resolve(AddFavoriteRecord::class)->execute(self::member(), $record, $folder);
        $livewire->dispatch('refresh-sidebar');
    }

    /**
     * @param  Collection<int, Favorite>  $folders
     * @param  Closure(): bool  $visible
     * @param  (Closure(): ?string)|null  $currentFolderId
     * @param  Closure(Component, ?Favorite): void  $choose
     */
    private static function folderPicker(Collection $folders, string $name, string $label, Closure $visible, ?Closure $currentFolderId, Closure $choose): ActionGroup
    {
        $choice = fn (?Favorite $folder): Action => self::folderChoice($name, $folder, $currentFolderId, $choose);

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
     * @param  Closure(Component, ?Favorite): void  $choose
     */
    private static function folderChoice(string $name, ?Favorite $folder, ?Closure $currentFolderId, Closure $choose): Action
    {
        $label = $folder instanceof Favorite ? e($folder->name) : e(__('favorites.actions.no_folder'));

        return Action::make($name.($folder?->getKey() ?? 'Top'))
            ->label(fn (): HtmlString => new HtmlString(
                $currentFolderId instanceof Closure && $currentFolderId() === $folder?->getKey()
                    ? '<span class="flex w-full items-center gap-2"><span class="truncate">'.$label.'</span>'.svg('lucide-check', 'fi-menu-check')->toHtml().'</span>'
                    : $label,
            ))
            ->icon($folder instanceof Favorite ? 'lucide-folder' : 'lucide-square-slash')
            ->action(function (Component $livewire) use ($choose, $folder): void {
                $choose($livewire, $folder);
            });
    }

    private static function favorite(Company|People|Opportunity $record): ?Favorite
    {
        return Favorite::ownedFor(self::member(), $record);
    }

    private static function member(): User
    {
        /** @var User */
        return auth()->user();
    }
}
