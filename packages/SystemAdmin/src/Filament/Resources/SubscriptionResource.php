<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Cashier\Subscription;
use Override;
use Relaticle\SystemAdmin\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use Relaticle\SystemAdmin\Filament\Resources\SubscriptionResource\Pages\ViewSubscription;
use Relaticle\SystemAdmin\Filament\Support\RecordLink;
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

    public static function getNavigationBadge(): ?string
    {
        $count = self::getModel()::query()->count();

        return $count > 0 ? (string) $count : null;
    }

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('owner');
    }

    public static function planLabel(?string $state): string
    {
        return match (true) {
            $state === config('services.stripe.prices.pro_monthly') => 'Pro · monthly',
            $state === config('services.stripe.prices.pro_yearly') => 'Pro · yearly',
            default => $state ?? '—',
        };
    }

    public static function statusColor(string $state): string
    {
        return match ($state) {
            'active', 'trialing' => 'success',
            'past_due' => 'warning',
            default => 'gray',
        };
    }

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make([
                    TextEntry::make('owner.name')
                        ->label('Team')
                        ->placeholder('—')
                        ->color('primary')
                        ->url(RecordLink::to(TeamResource::class, 'owner')),
                    TextEntry::make('type')
                        ->label('Type'),
                    TextEntry::make('stripe_price')
                        ->label('Plan')
                        ->formatStateUsing(self::planLabel(...)),
                    TextEntry::make('stripe_status')
                        ->label('Status')
                        ->badge()
                        ->color(self::statusColor(...)),
                    TextEntry::make('quantity')
                        ->numeric()
                        ->placeholder('—'),
                    TextEntry::make('stripe_id')
                        ->label('Stripe ID')
                        ->copyable(),
                    TextEntry::make('trial_ends_at')
                        ->label('Trial ends')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('ends_at')
                        ->label('Ends')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('created_at')
                        ->label('Started')
                        ->dateTime(),
                ])->columnSpanFull()->columns(2),
            ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('owner.name')
                    ->label('Team')
                    ->searchable()
                    ->placeholder('—')
                    ->color('primary')
                    ->url(RecordLink::to(TeamResource::class, 'owner')),
                TextColumn::make('stripe_price')
                    ->label('Plan')
                    ->formatStateUsing(self::planLabel(...)),
                TextColumn::make('stripe_status')
                    ->label('Status')
                    ->badge()
                    ->color(self::statusColor(...)),
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
                ViewAction::make(),
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
            'view' => ViewSubscription::route('/{record}'),
        ];
    }
}
