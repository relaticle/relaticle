<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\SystemAdmin\Filament\Resources\AgentConversationResource;
use Relaticle\SystemAdmin\Filament\Resources\UserResource;
use Relaticle\SystemAdmin\Filament\Support\RecordLink;

final class ConversationsRelationManager extends RelationManager
{
    protected static string $relationship = 'conversations';

    protected static ?string $title = 'AI Conversations';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-chat-bubble-left-right';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->conversations()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')
                    ->limit(50)
                    ->placeholder('—')
                    ->searchable()
                    ->color('primary')
                    ->url(fn (AgentConversation $record): string => AgentConversationResource::getUrl('view', ['record' => $record])),
                TextColumn::make('user.name')
                    ->label('User')
                    ->placeholder('—')
                    ->searchable()
                    ->color('primary')
                    ->url(RecordLink::to(UserResource::class, 'user')),
                TextColumn::make('messages_count')
                    ->label('Messages')
                    ->counts('messages')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
