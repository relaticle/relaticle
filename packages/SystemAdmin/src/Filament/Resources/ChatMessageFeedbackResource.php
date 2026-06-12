<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources;

use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Override;
use Relaticle\Chat\Models\ChatMessageFeedback;
use Relaticle\SystemAdmin\Filament\Resources\ChatMessageFeedbackResource\Pages\ListChatMessageFeedback;
use Relaticle\SystemAdmin\Filament\Resources\ChatMessageFeedbackResource\Pages\ViewChatMessageFeedback;

final class ChatMessageFeedbackResource extends Resource
{
    protected static ?string $model = ChatMessageFeedback::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-hand-thumb-up';

    protected static string|\UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'Message Feedback';

    protected static ?string $pluralModelLabel = 'Message Feedback';

    protected static ?string $slug = 'ai/message-feedback';

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make([
                    TextEntry::make('team.name')->label('Team'),
                    TextEntry::make('user.name')->label('User'),
                    TextEntry::make('rating')->badge()->color(fn (string $state): string => $state === ChatMessageFeedback::RATING_UP ? 'success' : 'danger'),
                    TextEntry::make('category')->placeholder('—'),
                    TextEntry::make('model')->placeholder('—'),
                    TextEntry::make('conversation_id')->label('Conversation')->copyable(),
                    TextEntry::make('message_id')->label('Message')->copyable(),
                    TextEntry::make('comment')->placeholder('—')->columnSpanFull(),
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
                TextColumn::make('team.name')
                    ->label('Team')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('rating')
                    ->badge()
                    ->color(fn (string $state): string => $state === ChatMessageFeedback::RATING_UP ? 'success' : 'danger'),
                TextColumn::make('category')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('comment')
                    ->limit(60)
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('model')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('rating')
                    ->options([
                        ChatMessageFeedback::RATING_UP => 'Up',
                        ChatMessageFeedback::RATING_DOWN => 'Down',
                    ]),
                SelectFilter::make('category')
                    ->options(array_combine(ChatMessageFeedback::CATEGORIES, ChatMessageFeedback::CATEGORIES)),
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
            'index' => ListChatMessageFeedback::route('/'),
            'view' => ViewChatMessageFeedback::route('/{record}'),
        ];
    }
}
