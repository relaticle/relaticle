<?php

declare(strict_types=1);

namespace App\Livewire\App\AccessTokens;

use App\Livewire\BaseLivewireComponent;
use App\Models\PersonalAccessToken;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Laravel\Jetstream\Jetstream;
use Livewire\Attributes\On;

final class ManageAccessTokens extends BaseLivewireComponent implements HasTable
{
    use InteractsWithTable;

    #[On('tokenCreated')]
    public function refreshTokenList(): void
    {
        // Filament table auto-refreshes on Livewire re-render
    }

    public function table(Table $table): Table
    {
        $user = $this->authUser();

        return $table
            ->query(
                fn (): Builder => PersonalAccessToken::query()
                    ->with('team')
                    ->where('tokenable_type', $user->getMorphClass())
                    ->where('tokenable_id', $user->getKey()),
            )
            ->columns([
                TextColumn::make('name')->label(__('access-tokens.table.columns.name'))->searchable(),
                TextColumn::make('team.name')
                    ->label(__('access-tokens.table.columns.team'))
                    ->placeholder(__('access-tokens.table.placeholders.no_team')),
                TextColumn::make('abilities')
                    ->label(__('access-tokens.table.columns.abilities'))
                    ->badge()
                    ->formatStateUsing(
                        fn (string $state): string => $state === '*'
                            ? __('access-tokens.permissions.all')
                            : ucfirst($state),
                    ),
                TextColumn::make('expires_at')
                    ->label(__('access-tokens.table.columns.expires_at'))
                    ->date()
                    ->placeholder(__('access-tokens.table.placeholders.never')),
                TextColumn::make('last_used_at')
                    ->label(__('access-tokens.table.columns.last_used_at'))
                    ->since()
                    ->placeholder(__('access-tokens.table.placeholders.never')),
                TextColumn::make('created_at')->label(__('access-tokens.table.columns.created_at'))->since(),
            ])
            ->actions([
                Action::make('permissions')
                    ->label(__('access-tokens.modals.permissions.action_label'))
                    ->icon('heroicon-o-lock-closed')
                    ->iconButton()
                    ->tooltip('Edit Permissions')
                    ->modalHeading(fn (PersonalAccessToken $record): string => "Permissions: {$record->name}")
                    ->modalWidth(Width::Large)
                    ->fillForm(
                        fn (PersonalAccessToken $record): array => [
                            'permissions' => $record->abilities,
                        ],
                    )
                    ->schema([
                        CreateAccessToken::permissionsCheckboxList(),
                    ])
                    ->action(function (
                        PersonalAccessToken $record,
                        array $data,
                    ): void {
                        $record
                            ->forceFill([
                                'abilities' => Jetstream::validPermissions(
                                    $data['permissions'] ?? [],
                                ),
                            ])
                            ->save();

                        $this->sendNotification(
                            title: __('access-tokens.notifications.permissions_updated'),
                        );
                    }),
                DeleteAction::make()
                    ->iconButton()
                    ->after(
                        fn () => $this->sendNotification(
                            title: __('access-tokens.notifications.deleted'),
                        ),
                    ),
            ])
            ->emptyStateHeading(__('access-tokens.empty_state.heading'))
            ->emptyStateDescription(__('access-tokens.empty_state.description'))
            ->paginated(false);
    }

    public function render(): View
    {
        return view('livewire.app.access-tokens.manage-access-tokens');
    }
}
