<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\AiCreditTransactions\Tables;

use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;

final class AiCreditTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('team.name')
                    ->label('Team')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('User')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('model')
                    ->searchable(),
                TextColumn::make('credits_charged')
                    ->label('Credits')
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
                    ->label('Conversation')
                    ->limit(8)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('team')
                    ->relationship('team', 'name')
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
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, mixed $date): Builder => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, mixed $date): Builder => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
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
