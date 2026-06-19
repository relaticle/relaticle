<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources;

use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Override;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\SystemAdmin\Filament\Resources\AgentConversationResource\Pages\ListAgentConversations;
use Relaticle\SystemAdmin\Filament\Resources\AgentConversationResource\Pages\ViewAgentConversation;
use UnitEnum;

final class AgentConversationResource extends Resource
{
    protected static ?string $model = AgentConversation::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string|UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'Conversation';

    protected static ?string $pluralModelLabel = 'Conversations';

    protected static ?string $slug = 'ai/conversations';

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make([
                    TextEntry::make('title'),
                    TextEntry::make('team.name')->label('Team')->placeholder('—'),
                    TextEntry::make('user.name')->label('User')->placeholder('—'),
                    TextEntry::make('messages_count')->label('Messages')->state(fn (AgentConversation $record): int => $record->messages()->count()),
                    TextEntry::make('id')->label('Conversation ID')->copyable(),
                    TextEntry::make('created_at')->dateTime(),
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
                TextColumn::make('title')
                    ->limit(50)
                    ->searchable(),
                TextColumn::make('team.name')
                    ->label('Team')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('User')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('messages_count')
                    ->label('Messages')
                    ->counts('messages')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('team')
                    ->relationship('team', 'name')
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListAgentConversations::route('/'),
            'view' => ViewAgentConversation::route('/{record}'),
        ];
    }
}
