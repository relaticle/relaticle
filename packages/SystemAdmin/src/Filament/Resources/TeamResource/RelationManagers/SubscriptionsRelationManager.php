<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\Subscription;
use Relaticle\SystemAdmin\Filament\Resources\SubscriptionResource;

final class SubscriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'subscriptions';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-credit-card';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->subscriptions()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('stripe_price')
                    ->label('Plan')
                    ->formatStateUsing(SubscriptionResource::planLabel(...))
                    ->color('primary')
                    ->url(fn (Subscription $record): string => SubscriptionResource::getUrl('view', ['record' => $record])),
                TextColumn::make('stripe_status')
                    ->label('Status')
                    ->badge()
                    ->color(SubscriptionResource::statusColor(...)),
                TextColumn::make('ends_at')
                    ->label('Ends')
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
