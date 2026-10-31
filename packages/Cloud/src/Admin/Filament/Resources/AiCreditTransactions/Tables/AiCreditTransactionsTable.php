<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Filament\Resources\AiCreditTransactions\Tables;

use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\SystemAdmin\Filament\Resources\UserResource;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;
use Relaticle\SystemAdmin\Filament\Support\RecordLink;
use Relaticle\SystemAdmin\Filament\Support\ViewerTime;
use Relaticle\SystemAdmin\Metrics\Money;

final class AiCreditTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns(self::columns())
            ->filters(self::filters())
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /**
     * @return list<Column>
     */
    private static function columns(): array
    {
        return [
            TextColumn::make('created_at')
                ->dateTime()
                ->sortable(),
            TextColumn::make('workspace.name')
                ->label(__('cloud::admin.credit_transactions.workspace'))
                ->searchable()
                ->sortable()
                ->color('primary')
                ->url(RecordLink::to(WorkspaceResource::class, 'workspace')),
            TextColumn::make('user.name')
                ->label(__('cloud::admin.credit_transactions.user'))
                ->placeholder(Money::EMPTY)
                ->searchable()
                ->sortable()
                ->toggleable()
                ->color('primary')
                ->url(RecordLink::to(UserResource::class, 'user')),
            TextColumn::make('type')
                ->badge(),
            TextColumn::make('model')
                ->searchable(),
            TextColumn::make('credits_charged')
                ->label(__('cloud::admin.credit_transactions.credits'))
                ->numeric()
                ->sortable(),
            TextColumn::make('input_tokens')
                ->numeric()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('output_tokens')
                ->numeric()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('conversation_id')
                ->label(__('cloud::admin.credit_transactions.conversation'))
                ->limit(8)
                ->tooltip(fn (?string $state): ?string => $state)
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    /**
     * @return list<BaseFilter>
     */
    private static function filters(): array
    {
        return [
            SelectFilter::make('workspace')
                ->relationship('workspace', 'name')
                ->searchable(),
            SelectFilter::make('type')
                ->options(
                    collect(AiCreditType::cases())
                        ->mapWithKeys(fn (AiCreditType $case): array => [
                            $case->value => $case->getLabel(),
                        ])
                ),
            SelectFilter::make('model')
                ->options(self::modelOptions()),
            Filter::make('date_range')
                ->schema([
                    DatePicker::make('from'),
                    DatePicker::make('until'),
                ])
                /**
                 * The picked dates are days on the administrator's calendar,
                 * which is what the table renders too, so they widen to that
                 * day's UTC bounds rather than being compared with whereDate.
                 */
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $q, mixed $date): Builder => $q->where('created_at', '>=', ViewerTime::startOfDayUtc((string) $date)))
                    ->when($data['until'] ?? null, fn (Builder $q, mixed $date): Builder => $q->where('created_at', '<=', ViewerTime::endOfDayUtc((string) $date)))),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function modelOptions(): array
    {
        return AiCreditTransaction::query()
            ->select('model')
            ->distinct()
            ->orderBy('model')
            ->pluck('model', 'model')
            ->all();
    }
}
