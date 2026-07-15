<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources;

use App\Models\ActivityLog\Activity;
use App\Models\ActivityLog\Scopes\TeamScope;
use App\Models\Team;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Override;
use Relaticle\SystemAdmin\Filament\Resources\ActivityResource\Pages\ListActivities;
use Relaticle\SystemAdmin\Filament\Resources\ActivityResource\Pages\ViewActivity;

final class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|\UnitEnum|null $navigationGroup = 'Dashboards';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Activity';

    protected static ?string $modelLabel = 'Activity';

    protected static ?string $pluralModelLabel = 'Activity';

    protected static ?string $slug = 'activity';

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope(TeamScope::class);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['team', 'causer']))
            ->columns([
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('team.name')
                    ->label('Team')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('causer.name')
                    ->label('User')
                    ->placeholder('System')
                    ->sortable(),
                TextColumn::make('subject_type')
                    ->label('Subject')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : ucfirst($state)),
                TextColumn::make('event')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'deleted' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('description')
                    ->limit(60)
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('team_id')
                    ->label('Team')
                    ->options(fn (): array => Team::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('subject_type')
                    ->label('Subject')
                    ->options([
                        'company' => 'Company',
                        'people' => 'People',
                        'opportunity' => 'Opportunity',
                        'task' => 'Task',
                        'note' => 'Note',
                    ]),
                SelectFilter::make('event')
                    ->options([
                        'created' => 'Created',
                        'updated' => 'Updated',
                        'deleted' => 'Deleted',
                    ]),
                SelectFilter::make('causer')
                    ->label('User')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('causer_type', 'user')->where('causer_id', $data['value'])
                        : $query),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['from'] ?? null), fn (Builder $q): Builder => $q->whereDate('activity_log.created_at', '>=', $data['from']))
                        ->when(filled($data['until'] ?? null), fn (Builder $q): Builder => $q->whereDate('activity_log.created_at', '<=', $data['until']))),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
            'view' => ViewActivity::route('/{record}'),
        ];
    }

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make([
                    TextEntry::make('created_at')->dateTime(),
                    TextEntry::make('event')
                        ->badge()
                        ->color(fn (?string $state): string => match ($state) {
                            'created' => 'success',
                            'deleted' => 'danger',
                            default => 'gray',
                        }),
                    TextEntry::make('team.name')->label('Team')->placeholder('—'),
                    TextEntry::make('causer.name')->label('User')->placeholder('System'),
                    TextEntry::make('subject_type')
                        ->label('Subject')
                        ->formatStateUsing(fn (?string $state, Activity $record): string => $state === null
                            ? '—'
                            : ucfirst($state).' #'.$record->subject_id),
                    TextEntry::make('description')->columnSpanFull(),
                ])->columns(2)->columnSpanFull(),
                Section::make('Changes')
                    ->schema([
                        TextEntry::make('changes')
                            ->hiddenLabel()
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('No field changes recorded.')
                            ->state(fn (Activity $record): array => self::buildChangeSummary($record))
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<int, string>
     */
    public static function buildChangeSummary(Activity $record): array
    {
        /** @var array<string, mixed> $properties */
        $properties = $record->properties?->toArray() ?? [];

        if (isset($properties['custom_field_changes']) && is_array($properties['custom_field_changes'])) {
            return collect($properties['custom_field_changes'])
                ->map(function (array $change): string {
                    $label = (string) ($change['label'] ?? $change['code'] ?? 'Field');
                    $old = self::stringifyValue($change['old'] ?? null);
                    $new = self::stringifyValue($change['new'] ?? null);

                    return "{$label}: {$old} → {$new}";
                })
                ->values()
                ->all();
        }

        if (isset($properties['attributes']) && is_array($properties['attributes'])) {
            /** @var array<string, mixed> $new */
            $new = $properties['attributes'];
            /** @var array<string, mixed> $old */
            $old = is_array($properties['old'] ?? null) ? $properties['old'] : [];

            return collect($new)
                ->map(fn (mixed $value, string $key): string => sprintf(
                    '%s: %s → %s',
                    $key,
                    self::stringifyValue($old[$key] ?? null),
                    self::stringifyValue($value),
                ))
                ->values()
                ->all();
        }

        if (isset($properties['old']) && is_array($properties['old']) && ! isset($properties['attributes'])) {
            /** @var array<string, mixed> $old */
            $old = $properties['old'];

            return collect($old)
                ->map(fn (mixed $value, string $key): string => sprintf(
                    '%s: %s → %s',
                    $key,
                    self::stringifyValue($value),
                    self::stringifyValue(null),
                ))
                ->values()
                ->all();
        }

        return collect($properties)
            ->map(fn (mixed $value, string $key): string => "{$key}: ".self::stringifyValue($value))
            ->values()
            ->all();
    }

    private static function stringifyValue(mixed $value): string
    {
        if (is_array($value)) {
            return (string) ($value['label'] ?? json_encode($value));
        }

        if ($value === null || $value === '') {
            return '—';
        }

        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }
}
