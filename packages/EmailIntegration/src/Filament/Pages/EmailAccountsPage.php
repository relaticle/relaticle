<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Size;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Session;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\GmailService;

final class EmailAccountsPage extends Page
{
    protected string $view = 'email-integration::filament.pages.email-accounts';

    protected static ?string $slug = 'settings/email-accounts';

    protected static ?string $title = 'Accounts';

    protected static ?int $navigationSort = 10;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static string|\UnitEnum|null $navigationGroup = 'Emails';

    /**
     * @var Collection<int, ConnectedAccount>
     */
    public Collection $connectedAccounts;

    public function mount(): void
    {
        $this->sendSuccessNotification();
        $this->connectedAccounts = $this->getAccounts();
    }

    /**
     * @return Collection<int, ConnectedAccount>
     */
    private function getAccounts(): Collection
    {
        return ConnectedAccount::query()->where('user_id', auth()->id())
            ->where('team_id', auth()->user()->currentTeam->getKey())
            ->get();
    }

    public function checkAttachmentAction(): Action
    {
        return Action::make('checkAttachment')
            ->action(function (): void {
                GmailService::forAccount(ConnectedAccount::query()->firstWhere('user_id', auth()->id()))->fetchMessage('19d82a37752febd1');
            });

    }

    public function connectGmailAction(): Action
    {
        return Action::make('connectGmail')
            ->label('Connect Gmail')
            ->icon('heroicon-o-plus')
            ->size(Size::Small)
            ->url(fn (): string => route('email-accounts.redirect', ['provider' => 'gmail']), true);
    }

    public function connectAzureAction(): Action
    {
        // TODO::Implement after azure setup
        return Action::make('connectAzure')
            ->label('Connect Outlook')
            ->icon('heroicon-o-plus')
            ->color('info')
            ->size(Size::Small)
            ->url(fn (): string => route('email-accounts.redirect', ['provider' => 'azure']), true);
    }

    public function reAuthAction(): Action
    {
        return Action::make('reAuth')
            ->label('Re-authenticate')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->size(Size::Small)
            ->url(fn (array $arguments): string => route('email-accounts.redirect', [
                'provider' => ConnectedAccount::query()->find($arguments['account_id'])?->provider->value,
            ]), true);
    }

    public function editSettingsAction(): Action
    {
        return Action::make('editSettings')
            ->label('Settings')
            ->icon('heroicon-o-cog-6-tooth')
            ->color('gray')
            ->size(Size::Small)
            ->fillForm(function (array $arguments): array {
                $account = ConnectedAccount::query()->find($arguments['account_id']);

                return [
                    'sync_inbox' => $account?->sync_inbox ?? true,
                    'sync_sent' => $account?->sync_sent ?? true,
                    'contact_creation_mode' => $account?->contact_creation_mode?->value ?? ContactCreationMode::None->value,
                    'auto_create_companies' => $account?->auto_create_companies ?? false,
                ];
            })
            ->schema([
                Grid::make(2)
                    ->schema([
                        Toggle::make('sync_inbox')
                            ->label('Sync inbox')
                            ->helperText('Sync incoming emails to this account.'),
                        Toggle::make('sync_sent')
                            ->label('Sync sent')
                            ->helperText('Sync emails you send from this account.'),
                    ]),
                Select::make('contact_creation_mode')
                    ->label('Auto-create contacts')
                    ->options(ContactCreationMode::class)
                    ->required()
                    ->helperText('Controls when new Person records are created from email participants.'),
                Toggle::make('auto_create_companies')
                    ->label('Auto-create companies')
                    ->helperText('Create Company records for unrecognised business domains (public domains like gmail.com are always excluded).'),
            ])
            ->action(function (array $arguments, array $data): void {
                ConnectedAccount::query()->where('id', $arguments['account_id'])
                    ->where('user_id', auth()->id())
                    ->update([
                        'sync_inbox' => $data['sync_inbox'],
                        'sync_sent' => $data['sync_sent'],
                        'contact_creation_mode' => $data['contact_creation_mode'],
                        'auto_create_companies' => $data['auto_create_companies'],
                    ]);
            })
            ->modalHeading('Account Settings')
            ->modalSubmitActionLabel('Save');
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
                ConnectedAccount::query()->where('id', $arguments['account_id'])
                    ->where('user_id', auth()->id())
                    ->delete();
            });
    }

    public function sendSuccessNotification(): void
    {
        if (Session::has('success')) {
            Notification::make()
                ->title(Session::get('success'))
                ->success()
                ->send();
        }
    }
}
