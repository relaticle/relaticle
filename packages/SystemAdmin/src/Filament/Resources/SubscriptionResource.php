<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources;

use App\Models\Team;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Cashier\Subscription;
use Override;
use Relaticle\SystemAdmin\Actions\TransferWorkspaceBilling;
use Relaticle\SystemAdmin\Exceptions\TransferRefused;
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
                self::transferAction(),
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

    private static function transferAction(): Action
    {
        return Action::make('transfer')
            ->label('Transfer to workspace')
            ->icon('heroicon-o-arrows-right-left')
            ->color('warning')
            ->authorize('transfer')
            ->visible(fn (Subscription $record): bool => $record->valid())
            ->modalHeading('Transfer billing to another workspace')
            ->modalDescription('Moves the Stripe customer and every subscription on it to the chosen workspace. Nothing changes in Stripe: the same card is charged on the same date. Invoice history follows the customer, and the customer keeps its current name, so rename it in the Stripe dashboard if that matters. Only workspaces with the same owner, no Stripe customer of their own, and not scheduled for deletion are listed.')
            ->modalSubmitActionLabel('Transfer')
            ->schema([
                Select::make('target_team_id')
                    ->label('Target workspace')
                    ->options(fn (Subscription $record): array => self::transferTargets($record))
                    ->required()
                    ->searchable()
                    ->native(false),
            ])
            ->action(function (array $data, Subscription $record, TransferWorkspaceBilling $transfer, Action $action): void {
                /** @var Team $source */
                $source = $record->owner;

                /** @var Team $target */
                $target = Team::query()->findOrFail((string) $data['target_team_id']);

                try {
                    $transfer->execute($source, $target, (string) auth('sysadmin')->id());
                } catch (TransferRefused $exception) {
                    Notification::make()
                        ->title('Transfer refused')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    $action->halt();
                }

                Notification::make()
                    ->title('Billing transferred')
                    ->body("{$source->name} to {$target->name}")
                    ->success()
                    ->send();
            });
    }

    /**
     * Workspaces this subscription can move to: same owner, no Stripe
     * customer of their own to be orphaned by the move, and not scheduled
     * for deletion.
     *
     * @return array<string, string>
     */
    public static function transferTargets(Subscription $record): array
    {
        /** @var Team $source */
        $source = $record->owner;

        return Team::query()
            ->where('user_id', $source->user_id)
            ->whereKeyNot($source->getKey())
            ->whereNull('stripe_id')
            ->whereNull('scheduled_deletion_at')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
