<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\Size;
use Illuminate\Database\Eloquent\Collection;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final class EmailAccounts extends Page
{
    protected string $view = 'filament.pages.email-accounts';

    protected static ?string $slug = 'settings/email-accounts';

    protected static ?string $title = 'Email Accounts';

    protected static ?int $navigationSort = 10;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    public function getAccounts(): Collection
    {
        return ConnectedAccount::where('user_id', auth()->id())
            ->where('team_id', auth()->user()->currentTeam->getKey())
            ->get();
    }

    public function connectGmailAction(): Action
    {
        return Action::make('connectGmail')
            ->label('Connect Gmail')
            ->icon('heroicon-o-plus')
            ->size(Size::Small)
            ->url(fn () => route('auth.socialite.redirect', ['provider' => 'gmail']), true);
    }

    public function disconnectAction(): Action
    {
        return Action::make('disconnect')
            ->label('Disconnect')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->size(Size::Small)
            ->requiresConfirmation()
            ->action(function (array $arguments): void {
                ConnectedAccount::where('id', $arguments['account_id'])
                    ->where('user_id', auth()->id())
                    ->delete();
            });
    }
}
