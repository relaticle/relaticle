<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources;

use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Override;
use Relaticle\Chat\Models\AgentConversationMessage;
use Relaticle\SystemAdmin\Filament\Resources\AgentConversationMessageResource\Pages\ListAgentConversationMessages;
use Relaticle\SystemAdmin\Filament\Resources\AgentConversationMessageResource\Pages\ViewAgentConversationMessage;
use UnitEnum;

final class AgentConversationMessageResource extends Resource
{
    protected static ?string $model = AgentConversationMessage::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static string|UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 50;

    protected static ?string $modelLabel = 'Message';

    protected static ?string $pluralModelLabel = 'Messages';

    protected static ?string $slug = 'ai/messages';

    private const array ROLE_COLORS = [
        'assistant' => 'primary',
        'user' => 'gray',
    ];

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make([
                    TextEntry::make('role')->badge()->color(fn (string $state): string => self::ROLE_COLORS[$state] ?? 'info'),
                    TextEntry::make('agent')->placeholder('—'),
                    TextEntry::make('user.name')->label('User')->placeholder('—'),
                    TextEntry::make('superseded_at')->dateTime()->placeholder('Live'),
                    TextEntry::make('conversation_id')->label('Conversation')->copyable(),
                    TextEntry::make('created_at')->dateTime(),
                    TextEntry::make('content')->placeholder('—')->columnSpanFull(),
                ])->columnSpanFull()->columns(2),
            ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state): string => self::ROLE_COLORS[$state] ?? 'info'),
                TextColumn::make('content')
                    ->limit(80)
                    ->placeholder('—')
                    ->wrap(),
                IconColumn::make('superseded_at')
                    ->label('Superseded')
                    ->boolean()
                    ->state(fn (AgentConversationMessage $record): bool => $record->superseded_at !== null),
                TextColumn::make('conversation_id')
                    ->label('Conversation')
                    ->limit(8)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->options([
                        'user' => 'User',
                        'assistant' => 'Assistant',
                    ]),
                TernaryFilter::make('superseded_at')
                    ->label('Superseded')
                    ->nullable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListAgentConversationMessages::route('/'),
            'view' => ViewAgentConversationMessage::route('/{record}'),
        ];
    }
}
