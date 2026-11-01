<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Filament\Resources;

use App\Enums\Plan;
use App\Enums\StripeSubscriptionStatus;
use App\Models\Workspace;
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
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Cashier\Subscription;
use Override;
use Relaticle\Cloud\Admin\Actions\TransferWorkspaceBilling;
use Relaticle\Cloud\Admin\Exceptions\TransferRefused;
use Relaticle\Cloud\Admin\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use Relaticle\Cloud\Admin\Filament\Resources\SubscriptionResource\Pages\ViewSubscription;
use Relaticle\Cloud\Admin\Metrics\Scopes\CountsTowardMrr;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;
use Relaticle\SystemAdmin\Filament\Support\RecordLink;
use Relaticle\SystemAdmin\Metrics\Money;

final class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'billing/subscriptions';

    #[Override]
    public static function getNavigationGroup(): string
    {
        return __('cloud::admin.navigation.billing');
    }

    #[Override]
    public static function getModelLabel(): string
    {
        return __('cloud::admin.subscriptions.model_label');
    }

    #[Override]
    public static function getPluralModelLabel(): string
    {
        return __('cloud::admin.subscriptions.plural_model_label');
    }

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

    /**
     * Filament injects closure arguments by parameter name, so the callable it
     * is handed must take `$state`. That is the only reason this sits between
     * the column and `Plan::stripePriceLabel()`.
     */
    public static function planLabel(?string $state): string
    {
        return Plan::stripePriceLabel($state);
    }

    /**
     * Shared with SubscriptionsRelationManager so the two tables cannot drift
     * apart again the way their colour maps did.
     */
    public static function statusColumn(): TextColumn
    {
        return TextColumn::make('stripe_status')
            ->label(__('cloud::admin.subscriptions.status'))
            ->state(fn (Subscription $record): StripeSubscriptionStatus|string => StripeSubscriptionStatus::forDisplay($record->stripe_status))
            ->tooltip(StripeSubscriptionStatus::tooltipFor(...))
            ->badge();
    }

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make([
                    TextEntry::make('owner.name')
                        ->label(__('cloud::admin.subscriptions.workspace'))
                        ->placeholder(Money::EMPTY)
                        ->color('primary')
                        ->url(RecordLink::to(WorkspaceResource::class, 'owner')),
                    TextEntry::make('type')
                        ->label(__('cloud::admin.subscriptions.type')),
                    TextEntry::make('stripe_price')
                        ->label(__('cloud::admin.subscriptions.plan'))
                        ->formatStateUsing(self::planLabel(...)),
                    TextEntry::make('stripe_status')
                        ->label(__('cloud::admin.subscriptions.status'))
                        ->state(fn (Subscription $record): StripeSubscriptionStatus|string => StripeSubscriptionStatus::forDisplay($record->stripe_status))
                        ->tooltip(StripeSubscriptionStatus::tooltipFor(...))
                        ->badge(),
                    TextEntry::make('quantity')
                        ->numeric()
                        ->placeholder(Money::EMPTY),
                    TextEntry::make('stripe_id')
                        ->label(__('cloud::admin.subscriptions.stripe_id'))
                        ->copyable(),
                    TextEntry::make('trial_ends_at')
                        ->label(__('cloud::admin.subscriptions.trial_ends'))
                        ->dateTime()
                        ->placeholder(Money::EMPTY),
                    TextEntry::make('ends_at')
                        ->label(__('cloud::admin.subscriptions.ends'))
                        ->dateTime()
                        ->placeholder(Money::EMPTY),
                    TextEntry::make('created_at')
                        ->label(__('cloud::admin.subscriptions.started'))
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
                    ->label(__('cloud::admin.subscriptions.workspace'))
                    ->searchable()
                    ->placeholder(Money::EMPTY)
                    ->color('primary')
                    ->url(RecordLink::to(WorkspaceResource::class, 'owner')),
                TextColumn::make('stripe_price')
                    ->label(__('cloud::admin.subscriptions.plan'))
                    ->formatStateUsing(self::planLabel(...)),
                self::statusColumn(),
                TextColumn::make('ends_at')
                    ->label(__('cloud::admin.subscriptions.ends'))
                    ->dateTime()
                    ->placeholder(Money::EMPTY),
                TextColumn::make('created_at')
                    ->label(__('cloud::admin.subscriptions.started'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('stripe_status')
                    ->label(__('cloud::admin.subscriptions.status'))
                    ->options(StripeSubscriptionStatus::class)
                    ->multiple(),
                Filter::make('counts_toward_mrr')
                    ->label(__('cloud::admin.subscriptions.counts_toward_mrr'))
                    ->toggle()
                    ->query(self::countingTowardMrr(...)),
            ])
            ->recordActions([
                ViewAction::make(),
                self::transferAction(),
                Action::make('stripe')
                    ->label(__('cloud::admin.subscriptions.open_in_stripe'))
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

    /**
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    private static function countingTowardMrr(Builder $query): Builder
    {
        (new CountsTowardMrr)->apply($query, $query->getModel());

        return $query;
    }

    private static function transferAction(): Action
    {
        return Action::make('transfer')
            ->label(__('cloud::admin.subscriptions.transfer'))
            ->icon('heroicon-o-arrows-right-left')
            ->color('warning')
            ->authorize('transfer')
            ->visible(fn (Subscription $record): bool => $record->valid() && self::transferTargets($record) !== [])
            ->modalHeading(__('cloud::admin.subscriptions.transfer_heading'))
            ->modalDescription('Moves the Stripe customer and every subscription on it to the chosen workspace. The subscription is not touched in Stripe: the same card is charged on the same date, and invoice history follows the customer. The customer is renamed to the target workspace so future invoices name the right one. Only workspaces with the same owner, no Stripe customer of their own, and not scheduled for deletion are listed.')
            ->modalSubmitActionLabel('Transfer')
            ->schema([
                Select::make('target_workspace_id')
                    ->label(__('cloud::admin.subscriptions.target_workspace'))
                    ->options(fn (Subscription $record): array => self::transferTargets($record))
                    ->required()
                    ->searchable()
                    ->native(false)
                    // The options are re-read on submit, so a workspace that became
                    // ineligible while this modal was open fails here rather than in
                    // the action. Say why, since the generic message reads as a bug.
                    ->validationMessages([
                        'in' => 'That workspace is no longer eligible: it may have started its own subscription, been scheduled for deletion, or already received this billing. Close this dialog and reopen it to see the current list.',
                    ]),
            ])
            ->action(function (array $data, Subscription $record, TransferWorkspaceBilling $transfer, Action $action): void {
                /** @var Workspace $source */
                $source = $record->owner;

                /** @var Workspace $target */
                $target = Workspace::query()->findOrFail((string) $data['target_workspace_id']);

                try {
                    $transfer->execute($source, $target, (string) auth('sysadmin')->id());
                } catch (TransferRefused $exception) {
                    Notification::make()
                        ->title(__('cloud::admin.subscriptions.transfer_refused'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    $action->halt();
                }

                Notification::make()
                    ->title(__('cloud::admin.subscriptions.transferred'))
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
        /** @var Workspace $source */
        $source = $record->owner;

        return Workspace::query()
            ->where('user_id', $source->user_id)
            ->whereKeyNot($source->getKey())
            ->whereNull('stripe_id')
            ->whereNull('scheduled_deletion_at')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
