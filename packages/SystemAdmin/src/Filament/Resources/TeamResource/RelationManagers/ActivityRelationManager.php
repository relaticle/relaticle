<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers;

use App\Models\ActivityLog\Activity;
use App\Models\Team;
use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Relaticle\SystemAdmin\Filament\Resources\ActivityResource;
use Relaticle\SystemAdmin\Filament\Support\RecordLink;

/**
 * One workspace's audit trail, read from the team page instead of the panel-wide
 * Activity list narrowed by hand. Rows link out to that list's view page, which
 * is where the field-level diff already renders.
 */
final class ActivityRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'Activity';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-clock';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->activities()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['causer', 'subject']))
            ->recordUrl(fn (Activity $record): string => ActivityResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('No activity yet')
            ->emptyStateDescription('Nothing has been created, updated or deleted in this workspace.')
            ->columns([
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('causer.name')
                    ->label('User')
                    ->placeholder('System')
                    ->color('primary')
                    ->url(RecordLink::toMorph(ActivityResource::causerResources(), 'causer_type', 'causer_id')),
                TextColumn::make('subject_type')
                    ->label('Subject')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(function (?string $state, Activity $record): string {
                        if ($state === null) {
                            return '—';
                        }

                        $name = ActivityResource::subjectName($record);

                        return $name === null ? ucfirst($state) : ucfirst($state).': '.$name;
                    })
                    ->url(RecordLink::toMorph(ActivityResource::subjectResources(), 'subject_type', 'subject_id')),
                TextColumn::make('event')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'deleted' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(ActivityResource::eventLabel(...)),
                TextColumn::make('properties')
                    ->label('Changes')
                    ->state(fn (Activity $record): array => ActivityResource::buildChangeSummary($record))
                    ->listWithLineBreaks()
                    ->limitList(1)
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->filters([
                ActivityResource::causerFilter(fn (): array => $this->memberOptions()),
                ...ActivityResource::commonFilters(),
            ]);
    }

    /**
     * Only the workspace's own members can have caused anything in it, so the
     * filter lists them rather than every user in the system.
     *
     * @return array<string, string>
     */
    private function memberOptions(): array
    {
        $team = $this->getOwnerRecord();

        if (! $team instanceof Team) {
            return [];
        }

        return $team->allUsers()
            ->sortBy('name')
            ->mapWithKeys(fn (User $user): array => [(string) $user->getKey() => $user->name])
            ->all();
    }
}
