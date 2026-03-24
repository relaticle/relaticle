<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompanyResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;

final class EmailsRelationManager extends RelationManager
{
    protected static string $relationship = 'emails';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-envelope';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('subject')
            ->defaultSort('sent_at', 'desc')
            ->columns([
                TextColumn::make('subject')
                    ->label('Subject')
                    ->searchable()
                    ->placeholder('(no subject)')
                    ->limit(60),

                TextColumn::make('from_address')
                    ->label('From')
                    ->getStateUsing(fn ($record): string => $record->from->first()?->name
                        ?? $record->from->first()?->email_address
                        ?? '—'),

                BadgeColumn::make('direction')
                    ->label('Direction')
                    ->formatStateUsing(fn ($state) => $state->getLabel()),

                TextColumn::make('sent_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),

                BadgeColumn::make('privacy_tier')
                    ->label('Visibility')
                    ->formatStateUsing(fn (EmailPrivacyTier $state) => $state->getLabel())
                    ->color(fn (EmailPrivacyTier $state) => match ($state) {
                        EmailPrivacyTier::PRIVATE => 'gray',
                        EmailPrivacyTier::METADATA_ONLY => 'gray',
                        EmailPrivacyTier::SUBJECT => 'warning',
                        EmailPrivacyTier::FULL => 'success',
                    }),
            ])
            ->recordActions([
                ViewAction::make()->slideOver(),
            ]);
    }
}
