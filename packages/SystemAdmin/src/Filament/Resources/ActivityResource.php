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
use Illuminate\Support\Str;
use Override;
use Relaticle\SystemAdmin\Filament\Resources\ActivityResource\Pages\ListActivities;
use Relaticle\SystemAdmin\Filament\Resources\ActivityResource\Pages\ViewActivity;
use Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\SystemAdministratorResource;
use Relaticle\SystemAdmin\Filament\Support\RecordLink;

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

    /**
     * @return array<string, class-string> Morph alias => Filament resource class
     */
    public static function causerResources(): array
    {
        return [
            'user' => UserResource::class,
            'system_administrator' => SystemAdministratorResource::class,
        ];
    }

    /**
     * @return array<string, class-string> Morph alias => Filament resource class
     */
    public static function subjectResources(): array
    {
        return [
            'company' => CompanyResource::class,
            'people' => PeopleResource::class,
            'opportunity' => OpportunityResource::class,
            'task' => TaskResource::class,
            'note' => NoteResource::class,
            'team' => TeamResource::class,
            'user' => UserResource::class,
        ];
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['team', 'causer', 'subject']))
            ->columns([
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('team.name')
                    ->label('Team')
                    ->placeholder('—')
                    ->sortable()
                    ->color('primary')
                    ->url(RecordLink::to(TeamResource::class, 'team')),
                TextColumn::make('causer.name')
                    ->label('User')
                    ->placeholder('System')
                    ->color('primary')
                    ->url(RecordLink::toMorph(self::causerResources(), 'causer_type', 'causer_id')),
                TextColumn::make('subject_type')
                    ->label('Subject')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(function (?string $state, Activity $record): string {
                        if ($state === null) {
                            return '—';
                        }

                        $name = self::subjectName($record);

                        return $name === null ? ucfirst($state) : ucfirst($state).': '.$name;
                    })
                    ->url(RecordLink::toMorph(self::subjectResources(), 'subject_type', 'subject_id')),
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
                    TextEntry::make('team.name')
                        ->label('Team')
                        ->placeholder('—')
                        ->color('primary')
                        ->url(RecordLink::to(TeamResource::class, 'team')),
                    TextEntry::make('causer.name')
                        ->label('User')
                        ->placeholder('System')
                        ->color('primary')
                        ->url(RecordLink::toMorph(self::causerResources(), 'causer_type', 'causer_id')),
                    TextEntry::make('subject_type')
                        ->label('Subject')
                        ->formatStateUsing(function (?string $state, Activity $record): string {
                            if ($state === null) {
                                return '—';
                            }

                            $name = self::subjectName($record);

                            return $name === null
                                ? ucfirst($state).' #'.$record->subject_id
                                : ucfirst($state).': '.$name;
                        })
                        ->color('primary')
                        ->url(RecordLink::toMorph(self::subjectResources(), 'subject_type', 'subject_id')),
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
     * The subject's display name: CRM records and teams/users use `name`,
     * tasks and notes use `title`. Soft-deleted subjects still resolve
     * (the activity relation loads trashed models); null only when the
     * subject was hard-deleted or has neither attribute.
     */
    public static function subjectName(Activity $record): ?string
    {
        $subject = $record->subject;

        if ($subject === null) {
            return null;
        }

        $name = $subject->getAttribute('name') ?? $subject->getAttribute('title');

        return is_string($name) && $name !== '' ? Str::limit($name, 40) : null;
    }

    /**
     * @return array<int, string>
     */
    public static function buildChangeSummary(Activity $record): array
    {
        // Spatie activitylog v5 stores trait-logged native diffs in `attribute_changes`,
        // while manual `activity()->withProperties()` calls (custom-field edits, etc.)
        // use `properties`. Merge both, mirroring the timeline's ActivityLogSource.
        /** @var array<string, mixed> $properties */
        $properties = [
            ...($record->properties?->toArray() ?? []),
            ...($record->attribute_changes?->toArray() ?? []),
        ];

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
