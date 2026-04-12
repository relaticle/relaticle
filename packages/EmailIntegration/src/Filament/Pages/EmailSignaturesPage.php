<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Size;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Collection;
use Relaticle\EmailIntegration\Actions\CreateSignatureAction;
use Relaticle\EmailIntegration\Actions\UpdateSignatureAction;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailSignature;

final class EmailSignaturesPage extends Page
{
    protected string $view = 'email-integration::filament.pages.email-signatures';

    protected static ?string $slug = 'settings/email-signatures';

    protected static ?string $title = 'Signatures';

    protected static ?int $navigationSort = 15;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-pencil';

    protected static string|\UnitEnum|null $navigationGroup = 'Emails';

    /**
     * @var Collection<int, EmailSignature>
     */
    public Collection $signatures;

    public function mount(): void
    {
        $this->signatures = $this->loadSignatures();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->createSignatureAction(),
        ];
    }

    /**
     * @return Collection<int, EmailSignature>
     */
    private function loadSignatures(): Collection
    {
        return EmailSignature::query()
            ->whereHas('connectedAccount', fn (Builder $q) => $q
                ->where('user_id', auth()->id())
                ->where('team_id', filament()->getTenant()?->getKey())
            )
            ->with('connectedAccount')
            ->get();
    }

    public function createSignatureAction(): Action
    {
        return Action::make('createSignature')
            ->label('New Signature')
            ->icon('heroicon-o-plus')
            ->size(Size::Small)
            ->schema([
                Select::make('connected_account_id')
                    ->label('Email account')
                    ->options(fn (): array => ConnectedAccount::query()
                        ->where('user_id', auth()->id())
                        ->where('team_id', filament()->getTenant()?->getKey())
                        ->where('status', 'active')
                        ->get()
                        ->mapWithKeys(fn (ConnectedAccount $account): array => [
                            $account->getKey() => $account->label,
                        ])
                        ->all()
                    )
                    ->required(),

                TextInput::make('name')
                    ->label('Signature name')
                    ->required()
                    ->maxLength(100),

                RichEditor::make('content_html')
                    ->label('Signature content')
                    ->required()
                    ->toolbarButtons(['bold', 'italic', 'underline', 'link']),

                Toggle::make('is_default')
                    ->label('Set as default for this account'),
            ])
            ->action(function (array $data, CreateSignatureAction $createSignatureAction): void {
                /** @var ConnectedAccount $account */
                $account = ConnectedAccount::query()->whereKey($data['connected_account_id'])->firstOrFail();

                $createSignatureAction->execute($account, [
                    'name' => $data['name'],
                    'content_html' => $data['content_html'],
                    'is_default' => (bool) ($data['is_default'] ?? false),
                ]);

                $this->signatures = $this->loadSignatures();

                Notification::make()
                    ->title('Signature created.')
                    ->success()
                    ->send();
            });
    }

    public function editSignatureAction(): Action
    {
        return Action::make('editSignature')
            ->label('Edit')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->size(Size::Small)
            ->fillForm(function (array $arguments): array {
                /** @var EmailSignature|null $signature */
                $signature = EmailSignature::query()->whereKey($arguments['signature_id'])->first();

                return [
                    'name' => $signature === null ? '' : $signature->name,
                    'content_html' => $signature === null ? '' : $signature->content_html,
                    'is_default' => $signature === null ? false : $signature->is_default,
                ];
            })
            ->schema([
                TextInput::make('name')
                    ->label('Signature name')
                    ->required()
                    ->maxLength(100),

                RichEditor::make('content_html')
                    ->label('Signature content')
                    ->required()
                    ->toolbarButtons(['bold', 'italic', 'underline', 'link']),

                Toggle::make('is_default')
                    ->label('Set as default for this account'),
            ])
            ->action(function (array $arguments, array $data, UpdateSignatureAction $updateSignatureAction): void {
                /** @var EmailSignature $signature */
                $signature = EmailSignature::query()->whereKey($arguments['signature_id'])->firstOrFail();

                $updateSignatureAction->execute($signature, [
                    'name' => $data['name'],
                    'content_html' => $data['content_html'],
                    'is_default' => (bool) ($data['is_default'] ?? false),
                ]);

                $this->signatures = $this->loadSignatures();

                Notification::make()
                    ->title('Signature updated.')
                    ->success()
                    ->send();
            });
    }

    public function deleteSignatureAction(): Action
    {
        return Action::make('deleteSignature')
            ->label('Delete')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->size(Size::Small)
            ->requiresConfirmation()
            ->action(function (array $arguments): void {
                EmailSignature::query()->where('id', $arguments['signature_id'])
                    ->whereHas('connectedAccount', fn (Builder $q) => $q->where('user_id', auth()->id()))
                    ->delete();

                $this->signatures = $this->loadSignatures();

                Notification::make()
                    ->title('Signature deleted.')
                    ->success()
                    ->send();
            });
    }
}
