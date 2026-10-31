<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Filament\Resources;

use App\Enums\BillingStatus;
use App\Enums\Plan;
use App\Enums\SubscriptionType;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Override;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Cloud\Admin\Actions\ResetCreditPeriod;
use Relaticle\Cloud\Admin\Filament\Resources\AiCreditBalanceResource\Pages\EditAiCreditBalance;
use Relaticle\Cloud\Admin\Filament\Resources\AiCreditBalanceResource\Pages\ListAiCreditBalances;
use Relaticle\Cloud\Admin\Filament\Resources\AiCreditBalanceResource\Pages\ViewAiCreditBalance;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;
use Relaticle\SystemAdmin\Filament\Support\RecordLink;
use Relaticle\SystemAdmin\Filament\Support\ViewerTime;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final class AiCreditBalanceResource extends Resource
{
    protected static ?string $model = AiCreditBalance::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'ai/credit-balances';

    #[Override]
    public static function getNavigationGroup(): string
    {
        return __('cloud::admin.navigation.ai');
    }

    #[Override]
    public static function getModelLabel(): string
    {
        return __('cloud::admin.credit_balances.model_label');
    }

    #[Override]
    public static function getPluralModelLabel(): string
    {
        return __('cloud::admin.credit_balances.plural_model_label');
    }

    #[Override]
    public static function canAccess(): bool
    {
        return BillingStatus::billingEnabled() && parent::canAccess();
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('credits_remaining')
                    ->numeric()
                    ->minValue(fn (?AiCreditBalance $record): int => $record->purchased_credits ?? 0)
                    ->helperText(__('cloud::admin.credit_balances.credits_remaining_help'))
                    ->required(),
                TextInput::make('credits_used')
                    ->numeric()
                    ->minValue(0)
                    ->disabled(),
                DateTimePicker::make('period_starts_at')
                    ->required(),
                DateTimePicker::make('period_ends_at')
                    ->required()
                    ->after('period_starts_at'),
            ]);
    }

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make([
                    TextEntry::make('workspace.name')
                        ->label(__('cloud::admin.credit_balances.workspace'))
                        ->color('primary')
                        ->url(RecordLink::to(WorkspaceResource::class, 'workspace')),
                    TextEntry::make('credits_remaining')->numeric(),
                    TextEntry::make('credits_used')->numeric(),
                    TextEntry::make('purchased_credits')
                        ->numeric()
                        ->label(__('cloud::admin.credit_balances.purchased_credits'))
                        ->helperText(__('cloud::admin.credit_balances.purchased_credits_help')),
                    TextEntry::make('period_starts_at')->dateTime(),
                    TextEntry::make('period_ends_at')->dateTime(),
                    TextEntry::make('updated_at')->dateTime(),
                ])->columnSpanFull()->columns(2),
            ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('credits_remaining', 'asc')
            ->columns(self::columns())
            ->filters(self::filters())
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                self::adjustAction(),
                self::resetPeriodAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::resetPeriodBulkAction(),
                ]),
            ]);
    }

    /**
     * @return list<Column>
     */
    private static function columns(): array
    {
        return [
            TextColumn::make('workspace.name')
                ->label(__('cloud::admin.credit_balances.workspace'))
                ->searchable()
                ->sortable()
                ->color('primary')
                ->url(RecordLink::to(WorkspaceResource::class, 'workspace')),
            TextColumn::make('billing_status')
                ->label(__('cloud::admin.credit_balances.billing'))
                ->state(fn (AiCreditBalance $record): BillingStatus => $record->workspace->billingStatus())
                ->tooltip(fn (BillingStatus $state): string => $state->getDescription())
                ->badge(),
            TextColumn::make('credits_remaining')
                ->numeric()
                ->sortable()
                ->badge()
                ->color(fn (int $state): string => match (true) {
                    $state === 0 => 'danger',
                    $state < 50 => 'warning',
                    default => 'success',
                }),
            TextColumn::make('credits_used')
                ->numeric()
                ->sortable(),
            TextColumn::make('purchased_credits')
                ->label(__('cloud::admin.credit_balances.purchased'))
                ->numeric()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            /**
             * A date-only column resolves its zone to config('app.timezone'),
             * not FilamentTimezone, so the period bounds have to name the
             * viewer's zone or the table shows a different day than the
             * infolist does for the same record.
             */
            TextColumn::make('period_starts_at')
                ->date()
                ->timezone(fn (): string => ViewerTime::timezone())
                ->sortable(),
            TextColumn::make('period_ends_at')
                ->date()
                ->timezone(fn (): string => ViewerTime::timezone())
                ->sortable()
                ->badge()
                ->color(fn (?CarbonInterface $state): string => $state?->isPast() ? 'danger' : 'gray'),
            TextColumn::make('updated_at')
                ->dateTime()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    /**
     * @return list<BaseFilter>
     */
    private static function filters(): array
    {
        return [
            Filter::make('low_balance')
                ->label(__('cloud::admin.credit_balances.low_balance'))
                ->query(fn (Builder $query): Builder => $query->where('credits_remaining', '<', 50)),
            Filter::make('period_expired')
                ->label(__('cloud::admin.credit_balances.period_expired'))
                ->query(fn (Builder $query): Builder => $query->where('period_ends_at', '<', now())),
            Filter::make('trialing')
                ->label(__('cloud::admin.credit_balances.trialing'))
                ->toggle()
                ->query(fn (Builder $query): Builder => $query->whereIn(
                    'workspace_id',
                    BillingStatus::Trialing->applyToQuery(Workspace::query())->select('id'),
                )),
            SelectFilter::make('workspace')
                ->relationship('workspace', 'name')
                ->searchable(),
        ];
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListAiCreditBalances::route('/'),
            'view' => ViewAiCreditBalance::route('/{record}'),
            'edit' => EditAiCreditBalance::route('/{record}/edit'),
        ];
    }

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('workspace.subscriptions');
    }

    private static function adjustAction(): Action
    {
        return Action::make('adjust')
            ->label(__('cloud::admin.credit_balances.adjust'))
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('warning')
            ->authorize('update')
            ->modalHeading(__('cloud::admin.credit_balances.adjust_heading'))
            ->modalDescription('Add or subtract credits. Positive values grant credits; negative values revoke them.')
            ->schema([
                TextInput::make('delta')
                    ->label(__('cloud::admin.credit_balances.credit_delta'))
                    ->integer()
                    ->required()
                    ->helperText(__('cloud::admin.credit_balances.credit_delta_help')),
                Textarea::make('reason')
                    ->required()
                    ->minLength(3)
                    ->maxLength(500),
            ])
            ->action(function (array $data, AiCreditBalance $record, CreditService $service): void {
                $sysadminId = (string) auth('sysadmin')->id();

                $service->adjust(
                    workspace: $record->workspace,
                    delta: (int) $data['delta'],
                    reason: (string) $data['reason'],
                    sysadminId: $sysadminId,
                );

                Notification::make()
                    ->title(__('cloud::admin.credit_balances.adjusted'))
                    ->success()
                    ->send();
            });
    }

    private static function resetPeriodAction(): Action
    {
        return Action::make('resetPeriod')
            ->label(__('cloud::admin.credit_balances.reset_period'))
            ->icon('heroicon-o-arrow-path')
            ->color('danger')
            ->authorize('update')
            ->requiresConfirmation()
            ->modalHeading(__('cloud::admin.credit_balances.reset_heading'))
            ->modalDescription(fn (AiCreditBalance $record): string => 'Wipes credits_used and grants the allowance for the chosen plan. Starts a fresh monthly period.'
                .(($record->workspace->subscription()?->valid() ?? false)
                    ? ' WARNING: this workspace has an active Stripe subscription. Webhook sync re-asserts the subscribed plan over a Free or Pro grant. To bill Cloud Concierge or Enterprise, use Start managed plan on the workspace page instead.'
                    : '')
                .(($record->workspace->subscription(SubscriptionType::Managed->value)?->valid() ?? false)
                    ? ' WARNING: this workspace has a paid Cloud Concierge or Enterprise subscription. Webhook sync restores that plan over a Free grant. Cancel the subscription in Stripe first.'
                    : ''))
            ->schema([
                Select::make('plan')
                    ->options(self::planOptions())
                    ->required(),
            ])
            ->action(function (array $data, AiCreditBalance $record, ResetCreditPeriod $reset): void {
                $plan = Plan::from((string) $data['plan']);

                $reset->execute(self::administrator(), $plan, [$record->workspace]);

                Notification::make()
                    ->title(__('cloud::admin.credit_balances.period_reset'))
                    ->body("Granted {$plan->credits()} credits.")
                    ->success()
                    ->send();
            });
    }

    private static function resetPeriodBulkAction(): BulkAction
    {
        return BulkAction::make('resetPeriod')
            ->label(__('cloud::admin.credit_balances.reset_period'))
            ->icon('heroicon-o-arrow-path')
            ->color('danger')
            ->authorize('update')
            ->requiresConfirmation()
            ->modalHeading(__('cloud::admin.credit_balances.reset_selected_heading'))
            ->schema([
                Select::make('plan')
                    ->options(self::planOptions())
                    ->required(),
            ])
            ->action(function (array $data, EloquentCollection $records, ResetCreditPeriod $reset): void {
                $plan = Plan::from((string) $data['plan']);
                $count = $records->count();

                $reset->execute(self::administrator(), $plan, $records->pluck('workspace'));

                Notification::make()
                    ->title(__('cloud::admin.credit_balances.periods_reset'))
                    ->body("Granted {$plan->credits()} credits to {$count} workspaces.")
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function administrator(): SystemAdministrator
    {
        $administrator = auth('sysadmin')->user();

        abort_unless($administrator instanceof SystemAdministrator, 403);

        return $administrator;
    }

    /**
     * @return array<string, string>
     */
    private static function planOptions(): array
    {
        return collect(Plan::cases())
            ->mapWithKeys(fn (Plan $plan): array => [$plan->value => $plan->getLabel()])
            ->all();
    }
}
