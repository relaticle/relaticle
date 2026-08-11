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
                    ->label(__('Token Name'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('abilities')
                    ->label(__('Abilities'))
                    ->badge(),
                TextColumn::make('last_used_at')
                    ->label(__('Last Used'))
                    ->dateTime()
                    ->sortable()
                    ->placeholder(__('Never')),
                TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                Action::make('create-token')
                    ->label(__('Create Token'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Token Name'))
                            ->required()
                            ->maxLength(255)
                            ->placeholder(__('e.g. Claude Code, Cursor')),
                        CheckboxList::make('abilities')
                            ->label(__('Abilities'))
                            ->options([
                                'posts:read' => __('Read posts'),
                                'posts:create' => __('Create posts'),
                                'posts:update' => __('Update posts'),
                                'posts:delete' => __('Delete & restore posts'),
                                'categories:read' => __('Read categories'),
                                'categories:create' => __('Create categories'),
                                'categories:update' => __('Update categories'),
                                'categories:delete' => __('Delete & restore categories'),
                            ])
                            ->bulkToggleable()
                            ->columns(2)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $token = $this->getOwnerRecord()->createToken($data['name'], $data['abilities']);

                        $this->plainTextToken = $token->plainTextToken;

                        $this->replaceMountedAction('showCreatedToken');
                    }),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label(__('Revoke'))
                    ->requiresConfirmation()
                    ->modalHeading(__('Revoke Token'))
                    ->modalDescription(__('This will permanently revoke this token. Any MCP clients using it will lose access immediately.')),
            ]);
    }

    public function showCreatedTokenAction(): Action
    {
        return Action::make('showCreatedToken')
            ->modalHeading(__('API Token Created'))
            ->modalDescription(__('Make sure to copy your API token now. It won\'t be shown again.'))
            ->schema(fn (): array => [
                TextInput::make('plainTextToken')
                    ->label(__('API Token'))
                    ->default($this->plainTextToken)
                    ->readOnly()
                    ->extraInputAttributes([
                        'class' => 'font-mono text-xs',
                        'x-on:click' => '$el.select()',
                    ])
                    ->suffixAction(
                        Action::make('copyToken')
                            ->icon('heroicon-o-clipboard')
                            ->tooltip(__('Copy to clipboard'))
                            ->alpineClickHandler(sprintf(
                                'window.navigator.clipboard.writeText($wire.plainTextToken); $tooltip(%s);',
                                json_encode(__('Copied!'), JSON_THROW_ON_ERROR),
                            )),
                    ),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Done'))
            ->after(function (): void {
                $this->plainTextToken = '';
            });
    }
}
