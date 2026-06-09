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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Relaticle\EmailIntegration\Actions\CreateSignatureAction;
use Relaticle\EmailIntegration\Actions\UpdateSignatureAction;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailSignature;

final class EmailSignaturesPage extends Page
{
    use HasEmailFeatureFlag;

    protected string $view = 'email-integration::filament.pages.email-signatures';

    protected static ?string $slug = 'settings/email-signatures';

    protected static ?string $title = 'Signatures';

    protected static ?int $navigationSort = 6;

    protected static string|\UnitEnum|null $navigationGroup = null;

    public static function getNavigationGroup(): string
    {
        return __('filament/navigation.groups.emails');
    }

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
        return $this->ownedSignatures()
            ->with('connectedAccount')
            ->get();
    }

    /**
     * Signatures scoped to the current user and tenant.
     *
     * @return Builder<EmailSignature>
     */
    private function ownedSignatures(): Builder
    {
        return EmailSignature::query()
            ->where('team_id', filament()->getTenant()?->getKey())
            ->where('user_id', auth()->id());
    }

    private function findOwnedAccount(string $id): ConnectedAccount
    {
        /** @var ConnectedAccount */
        return ConnectedAccount::query()
            ->whereKey($id)
            ->where('user_id', auth()->id())
            ->where('team_id', filament()->getTenant()?->getKey())
            ->firstOrFail();
    }

    public function createSignatureAction(): Action
    {
        return Action::make('createSignature')
            ->label(__('filament/pages/email-signatures.actions.create'))
            ->icon('heroicon-o-plus')
            ->size(Size::Small)
            ->schema([
                Select::make('connected_account_id')
                    ->label(__('filament/pages/email-signatures.fields.connected_account'))
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
                    ->label(__('filament/pages/email-signatures.fields.name'))
                    ->required()
                    ->maxLength(100),

                RichEditor::make('content_html')
                    ->label(__('filament/pages/email-signatures.fields.content'))
                    ->required()
                    ->toolbarButtons(['bold', 'italic', 'underline', 'link']),

                Toggle::make('is_default')
                    ->label(__('filament/pages/email-signatures.fields.is_default')),
            ])
            ->action(function (array $data, CreateSignatureAction $createSignatureAction): void {
                $account = $this->findOwnedAccount($data['connected_account_id']);

                $createSignatureAction->execute($account, [
                    'name' => $data['name'],
                    'content_html' => $data['content_html'],
                    'is_default' => (bool) ($data['is_default'] ?? false),
                ]);

                $this->signatures = $this->loadSignatures();

                Notification::make()
                    ->title(__('filament/pages/email-signatures.notifications.created'))
                    ->success()
                    ->send();
            });
    }

    public function editSignatureAction(): Action
    {
        return Action::make('editSignature')
            ->label(__('filament/pages/email-signatures.actions.edit'))
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->size(Size::Small)
            ->fillForm(function (array $arguments): array {
                $signature = $this->ownedSignatures()->whereKey($arguments['signature_id'])->first();

                return [
                    'name' => $signature === null ? '' : $signature->name,
                    'content_html' => $signature === null ? '' : $signature->content_html,
                    'is_default' => $signature === null ? false : $signature->is_default,
                ];
            })
            ->schema([
                TextInput::make('name')
                    ->label(__('filament/pages/email-signatures.fields.name'))
                    ->required()
                    ->maxLength(100),

                RichEditor::make('content_html')
                    ->label(__('filament/pages/email-signatures.fields.content'))
                    ->required()
                    ->toolbarButtons(['bold', 'italic', 'underline', 'link']),

                Toggle::make('is_default')
                    ->label(__('filament/pages/email-signatures.fields.is_default')),
            ])
            ->action(function (array $arguments, array $data, UpdateSignatureAction $updateSignatureAction): void {
                $signature = $this->ownedSignatures()->whereKey($arguments['signature_id'])->first();

                if ($signature === null) {
                    return;
                }

                $updateSignatureAction->execute($signature, [
                    'name' => $data['name'],
                    'content_html' => $data['content_html'],
                    'is_default' => (bool) ($data['is_default'] ?? false),
                ]);

                $this->signatures = $this->loadSignatures();

                Notification::make()
                    ->title(__('filament/pages/email-signatures.notifications.updated'))
                    ->success()
                    ->send();
            });
    }

    public function deleteSignatureAction(): Action
    {
        return Action::make('deleteSignature')
            ->label(__('filament/pages/email-signatures.actions.delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->size(Size::Small)
            ->requiresConfirmation()
            ->action(function (array $arguments): void {
                $deleted = $this->ownedSignatures()
                    ->whereKey($arguments['signature_id'])
                    ->delete();

                $this->signatures = $this->loadSignatures();

                if ($deleted > 0) {
                    Notification::make()
                        ->title(__('filament/pages/email-signatures.notifications.deleted'))
                        ->success()
                        ->send();
                }
            });
    }
}
