<?php

declare(strict_types=1);

namespace App\Livewire\App\ApiTokens;

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

final class ManageApiTokens extends BaseLivewireComponent implements HasTable
{
    use InteractsWithTable;

    /** @var array<string> */
    protected $listeners = ['tokenCreated' => '$refresh'];

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
                TextColumn::make('name')->label('Name')->searchable(),
                TextColumn::make('team.name')
                    ->label('Team')
                    ->placeholder('—'),
                TextColumn::make('abilities')
                    ->label('Permissions')
                    ->badge()
                    ->formatStateUsing(
                        fn (string $state): string => $state === '*'
                            ? 'All'
                            : ucfirst($state),
                    ),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->date()
                    ->placeholder('Never'),
                TextColumn::make('last_used_at')
                    ->label('Last Used')
                    ->since()
                    ->placeholder('Never'),
                TextColumn::make('created_at')->label('Created')->since(),
            ])
            ->actions([
                Action::make('permissions')
                    ->label('Permissions')
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
                        CreateApiToken::permissionsCheckboxList(),
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
        return view('livewire.app.api-tokens.manage-api-tokens');
    }
}
