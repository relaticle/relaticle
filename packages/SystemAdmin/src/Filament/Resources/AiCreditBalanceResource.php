<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources;

use App\Enums\Plan;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Override;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;
use Relaticle\SystemAdmin\Filament\Resources\AiCreditBalanceResource\Pages\EditAiCreditBalance;
use Relaticle\SystemAdmin\Filament\Resources\AiCreditBalanceResource\Pages\ListAiCreditBalances;
use Relaticle\SystemAdmin\Filament\Resources\AiCreditBalanceResource\Pages\ViewAiCreditBalance;

final class AiCreditBalanceResource extends Resource
{
    protected static ?string $model = AiCreditBalance::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Credit Balance';

    protected static ?string $pluralModelLabel = 'Credit Balances';

    protected static ?string $slug = 'ai/credit-balances';

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('credits_remaining')
                    ->numeric()
                    ->minValue(0)
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
                    TextEntry::make('team.name')->label('Team'),
                    TextEntry::make('credits_remaining')->numeric(),
                    TextEntry::make('credits_used')->numeric(),
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
            ->columns([
                TextColumn::make('team.name')
                    ->label('Team')
                    ->searchable()
                    ->sortable(),
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
                TextColumn::make('period_starts_at')
                    ->date()
                    ->sortable(),
                TextColumn::make('period_ends_at')
                    ->date()
                    ->sortable()
                    ->badge()
                    ->color(fn (?Carbon $state): string => $state?->isPast() ? 'danger' : 'gray'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('low_balance')
                    ->label('Low balance (< 50)')
                    ->query(fn (Builder $query): Builder => $query->where('credits_remaining', '<', 50)),
                Filter::make('period_expired')
                    ->label('Period expired')
                    ->query(fn (Builder $query): Builder => $query->where('period_ends_at', '<', now())),
                SelectFilter::make('team')
                    ->relationship('team', 'name')
                    ->searchable(),
            ])
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

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListAiCreditBalances::route('/'),
            'view' => ViewAiCreditBalance::route('/{record}'),
            'edit' => EditAiCreditBalance::route('/{record}/edit'),
        ];
    }

    private static function adjustAction(): Action
    {
        return Action::make('adjust')
            ->label('Adjust')
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('warning')
            ->authorize('update')
            ->modalHeading('Adjust credit balance')
            ->modalDescription('Add or subtract credits. Positive values grant credits; negative values revoke them.')
            ->schema([
                TextInput::make('delta')
                    ->label('Credit delta')
                    ->integer()
                    ->required()
                    ->helperText('Use a negative number to revoke credits.'),
                Textarea::make('reason')
                    ->required()
                    ->minLength(3)
                    ->maxLength(500),
            ])
            ->action(function (array $data, AiCreditBalance $record, CreditService $service): void {
                $sysadminId = (string) auth('sysadmin')->id();

                $service->adjust(
                    team: $record->team,
                    delta: (int) $data['delta'],
                    reason: (string) $data['reason'],
                    sysadminId: $sysadminId,
                );

                Notification::make()
                    ->title('Credit balance adjusted')
                    ->success()
                    ->send();
            });
    }

    private static function resetPeriodAction(): Action
    {
        return Action::make('resetPeriod')
            ->label('Reset period')
            ->icon('heroicon-o-arrow-path')
            ->color('danger')
            ->authorize('update')
            ->requiresConfirmation()
            ->modalHeading('Reset billing period')
            ->modalDescription('Wipes credits_used and grants the allowance for the chosen plan. Starts a fresh monthly period.')
            ->schema([
                Select::make('plan')
                    ->options(self::planOptions())
                    ->required(),
            ])
            ->action(function (array $data, AiCreditBalance $record, CreditService $service): void {
                $planString = (string) $data['plan'];
                $team = $record->team;
                $sysadminId = (string) auth('sysadmin')->id();

                DB::transaction(function () use ($team, $planString, $service, $sysadminId): void {
                    $team->plan = Plan::from($planString);
                    $team->save();
                    $service->resetPeriod($team, $sysadminId);
                });

                Notification::make()
                    ->title('Billing period reset')
                    ->body("Granted {$team->plan->credits()} credits.")
                    ->success()
                    ->send();
            });
    }

    private static function resetPeriodBulkAction(): BulkAction
    {
        return BulkAction::make('resetPeriod')
            ->label('Reset period')
            ->icon('heroicon-o-arrow-path')
            ->color('danger')
            ->authorize('update')
            ->requiresConfirmation()
            ->modalHeading('Reset billing period for selected teams')
            ->schema([
                Select::make('plan')
                    ->options(self::planOptions())
                    ->required(),
            ])
            ->action(function (array $data, EloquentCollection $records, CreditService $service): void {
                $planString = (string) $data['plan'];
                $plan = Plan::from($planString);
                $sysadminId = (string) auth('sysadmin')->id();
                $count = $records->count();

                DB::transaction(function () use ($records, $plan, $service, $sysadminId): void {
                    foreach ($records as $record) {
                        /** @var AiCreditBalance $record */
                        $team = $record->team;
                        $team->plan = $plan;
                        $team->save();
                        $service->resetPeriod($team, $sysadminId);
                    }
                });

                Notification::make()
                    ->title('Billing periods reset')
                    ->body("Granted {$plan->credits()} credits to {$count} teams.")
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * @return array<string, string>
     */
    private static function planOptions(): array
    {
        return collect(Plan::cases())
            ->mapWithKeys(fn (Plan $plan): array => [$plan->value => $plan->label()])
            ->all();
    }
}
