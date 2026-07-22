<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Laravel\Cashier\Subscription;
use Override;
use Relaticle\SystemAdmin\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use UnitEnum;

final class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Subscription';

    protected static ?string $pluralModelLabel = 'Subscriptions';

    protected static ?string $slug = 'billing/subscriptions';

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('owner.name')
                    ->label('Team')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('stripe_price')
                    ->label('Plan')
                    ->formatStateUsing(fn (?string $state): string => match (true) {
                        $state === config('services.stripe.prices.pro_monthly') => 'Pro · monthly',
                        $state === config('services.stripe.prices.pro_yearly') => 'Pro · yearly',
                        default => $state ?? '—',
                    }),
                TextColumn::make('stripe_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active', 'trialing' => 'success',
                        'past_due' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('ends_at')
                    ->label('Ends')
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('stripe_status')
                    ->label('Status')
                    ->options([
                        'active' => 'Active',
                        'trialing' => 'Trialing',
                        'past_due' => 'Past due',
                        'canceled' => 'Canceled',
                        'incomplete' => 'Incomplete',
                    ]),
            ])
            ->recordActions([
                Action::make('stripe')
                    ->label('Open in Stripe')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Subscription $record): string => "https://dashboard.stripe.com/subscriptions/{$record->stripe_id}")
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptions::route('/'),
        ];
    }
}
