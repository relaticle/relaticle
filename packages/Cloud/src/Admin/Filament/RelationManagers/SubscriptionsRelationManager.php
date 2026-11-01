<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Filament\RelationManagers;

use App\Models\Workspace;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\Subscription;
use Relaticle\Cloud\Admin\Filament\Resources\SubscriptionResource;
use Relaticle\SystemAdmin\Metrics\Money;

final class SubscriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'subscriptions';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-credit-card';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        if (! $ownerRecord instanceof Workspace) {
            return null;
        }

        $count = $ownerRecord->subscriptions()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('stripe_price')
                    ->label(__('cloud::admin.subscriptions.plan'))
                    ->formatStateUsing(SubscriptionResource::planLabel(...))
                    ->color('primary')
                    ->url(fn (Subscription $record): string => SubscriptionResource::getUrl('view', ['record' => $record])),
                SubscriptionResource::statusColumn(),
                TextColumn::make('ends_at')
                    ->label(__('cloud::admin.subscriptions.ends'))
                    ->dateTime()
                    ->placeholder(Money::EMPTY),
                TextColumn::make('created_at')
                    ->label(__('cloud::admin.subscriptions.started'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
