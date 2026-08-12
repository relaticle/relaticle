<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Relaticle\SystemAdmin\Enums\BlogTokenAbility;

final class ApiTokensRelationManager extends RelationManager
{
    protected static string $relationship = 'tokens';

    protected static ?string $title = 'API Tokens';

    public string $plainTextToken = '';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Token Name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('abilities')
                    ->label('Abilities')
                    ->badge(),
                TextColumn::make('last_used_at')
                    ->label('Last Used')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Never'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                Action::make('create-token')
                    ->label('Create Token')
                    ->authorize('create')
                    ->schema([
                        TextInput::make('name')
                            ->label('Token Name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. Claude Code, Cursor'),
                        CheckboxList::make('abilities')
                            ->label('Abilities')
                            ->options(BlogTokenAbility::options())
                            ->bulkToggleable()
                            ->columns(2)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        // Filament's CheckboxList dehydrates an `abilities.*` => in(...) rule
                        // from the same options() the enum populates, so a crafted request
                        // carrying an ability outside the eight above (e.g. `*`, which Sanctum
                        // treats as "every ability") is already rejected before this closure
                        // runs. The intersect below is a second, independent layer of
                        // defence-in-depth that enforces the same allowlist regardless of
                        // whether that Filament behaviour ever changes.
                        $abilities = array_values(array_intersect($data['abilities'], BlogTokenAbility::values()));

                        // A token minted here authenticates as this record from then on, so
                        // one administrator issuing a token "for" another would hand the
                        // issuer a live credential impersonating someone else.
                        abort_unless(
                            (string) auth('sysadmin')->id() === (string) $this->getOwnerRecord()->getKey(),
                            403,
                            'You may only issue API tokens for your own account.',
                        );

                        $token = $this->getOwnerRecord()->createToken($data['name'], $abilities);

                        $this->plainTextToken = $token->plainTextToken;

                        $this->replaceMountedAction('showCreatedToken');
                    }),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label('Revoke')
                    ->requiresConfirmation()
                    ->modalHeading('Revoke Token')
                    ->modalDescription('This will permanently revoke this token. Any MCP clients using it will lose access immediately.'),
            ]);
    }

    public function showCreatedTokenAction(): Action
    {
        return Action::make('showCreatedToken')
            ->modalHeading('API Token Created')
            ->modalDescription('Make sure to copy your API token now. It won\'t be shown again.')
            ->schema(fn (): array => [
                TextInput::make('plainTextToken')
                    ->label('API Token')
                    ->default($this->plainTextToken)
                    ->readOnly()
                    ->extraInputAttributes([
                        'class' => 'font-mono text-xs',
                        'x-on:click' => '$el.select()',
                    ])
                    ->suffixAction(
                        Action::make('copyToken')
                            ->icon('heroicon-o-clipboard')
                            ->tooltip('Copy to clipboard')
                            ->alpineClickHandler(sprintf(
                                'window.navigator.clipboard.writeText($wire.plainTextToken); $tooltip(%s);',
                                json_encode('Copied!', JSON_THROW_ON_ERROR),
                            )),
                    ),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Done');
    }

    /**
     * `showCreatedTokenAction()` has no submit action (`modalSubmitAction(false)`),
     * so closing it — via "Done", Escape, or the modal backdrop — always goes
     * through `unmountAction()`, never `callAfter()`. An `->after()` hook on that
     * action is therefore dead code that never runs, leaving the plaintext token
     * sitting in this component's public property (and re-serialized into the
     * page's Livewire snapshot on every later request) until the browser tab is
     * closed. Clearing it here, on the one path that's actually taken, is what
     * makes "shown once" true.
     */
    public function unmountAction(bool|string|null $cancelParentActions = null): void
    {
        $this->plainTextToken = '';

        parent::unmountAction($cancelParentActions);
    }
}
