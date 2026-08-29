<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\AgentConversationResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Relaticle\Chat\Models\AgentConversationMessage;
use Relaticle\SystemAdmin\Filament\Resources\AgentConversationMessageResource;
use Relaticle\SystemAdmin\Filament\Resources\UserResource;
use Relaticle\SystemAdmin\Filament\Support\RecordLink;

final class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-chat-bubble-left-right';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->messages()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->color('primary')
                    ->url(fn (AgentConversationMessage $record): string => AgentConversationMessageResource::getUrl('view', ['record' => $record])),
                TextColumn::make('role')
                    ->badge(),
                TextColumn::make('content')
                    ->limit(80)
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('user.name')
                    ->label('User')
                    ->placeholder('—')
                    ->color('primary')
                    ->url(RecordLink::to(UserResource::class, 'user')),
                IconColumn::make('superseded_at')
                    ->label('Superseded')
                    ->boolean()
                    ->state(fn (AgentConversationMessage $record): bool => $record->superseded_at !== null),
            ])
            ->defaultSort('created_at');
    }
}
