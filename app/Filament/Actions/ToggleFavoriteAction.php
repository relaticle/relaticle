<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Favorites\AddFavoriteRecord;
use App\Actions\Favorites\RemoveFavoriteRecord;
use App\Models\Company;
use App\Models\Favorite;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Support\Enums\Size;
use Livewire\Component;

final class ToggleFavoriteAction extends Action
{
    public static function getDefaultName(): string
    {
        return 'toggleFavorite';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->button()
            ->hiddenLabel()
            ->color('gray')
            ->size(Size::Small)
            ->icon('lucide-star')
            ->extraAttributes(fn (): array => $this->isStarred() ? ['class' => '[&_.fi-icon]:fill-warning-400 [&_.fi-icon]:text-warning-500'] : [])
            ->label(fn (): string => $this->isStarred() ? __('favorites.actions.remove') : __('favorites.actions.add'))
            ->tooltip(fn (): string => $this->isStarred() ? __('favorites.actions.remove') : __('favorites.actions.add'))
            ->action(function (Component $livewire): void {
                $user = $this->member();
                $record = $this->crmRecord();

                $this->isStarred()
                    ? resolve(RemoveFavoriteRecord::class)->execute($user, $record)
                    : resolve(AddFavoriteRecord::class)->execute($user, $record);

                $livewire->dispatch('refresh-sidebar');
            });
    }

    private function member(): User
    {
        /** @var User */
        return auth()->user();
    }

    private function crmRecord(): Company|People|Opportunity
    {
        /** @var Company|People|Opportunity */
        return $this->getRecord();
    }

    private function isStarred(): bool
    {
        return Favorite::ownedFor($this->member(), $this->crmRecord()) instanceof Favorite;
    }
}
