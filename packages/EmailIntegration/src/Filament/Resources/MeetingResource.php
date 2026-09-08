<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Resources;

use App\Models\Team;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Override;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Enums\CalendarEventStatus;
use Relaticle\EmailIntegration\Filament\Actions\ConfigureMailboxAction;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;
use Relaticle\EmailIntegration\Filament\Infolists\MeetingDetailInfolist;
use Relaticle\EmailIntegration\Filament\Resources\MeetingResource\Pages\ListMeetings;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\Scopes\VisibleMeetingScope;

final class MeetingResource extends Resource
{
    use HasEmailFeatureFlag;

    protected static ?string $model = Meeting::class;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 7;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    public static function getNavigationLabel(): string
    {
        return __('filament/resources/meeting.navigation_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MeetingDetailInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(60),
                TextColumn::make('starts_at')
                    ->label(__('filament/resources/meeting.columns.starts_at.label'))
                    ->dateTime('M j, Y · g:i a')
                    ->sortable(),
                TextColumn::make('organizer_name')
                    ->label(__('filament/resources/meeting.columns.organizer_name.label'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('attendees_count')
                    ->counts('attendees')
                    ->label(__('filament/resources/meeting.columns.attendees_count.label')),
                TextColumn::make('people_count')
                    ->counts('people')
                    ->label(__('filament/resources/meeting.columns.people_count.label'))
                    ->toggleable(),
                TextColumn::make('companies_count')
                    ->counts('companies')
                    ->label(__('filament/resources/meeting.columns.companies_count.label'))
                    ->toggleable(),
                TextColumn::make('opportunities_count')
                    ->counts('opportunities')
                    ->label(__('filament/resources/meeting.columns.opportunities_count.label'))
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('response_status')
                    ->label(__('filament/resources/meeting.columns.response_status.label'))
                    ->badge()
                    ->toggleable(),
            ])
            ->defaultSort('starts_at', 'desc')
            ->filters([
                Filter::make('upcoming')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('starts_at', '>=', now())),
                Filter::make('past')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('starts_at', '<', now())),
                SelectFilter::make('status')
                    ->options(CalendarEventStatus::class),
                SelectFilter::make('response_status')
                    ->label(__('filament/resources/meeting.filters.response_status.label'))
                    ->options(AttendeeResponseStatus::class),
            ])
            ->recordActions([
                MeetingDetailInfolist::viewAction(),
            ])
            ->emptyStateIcon('heroicon-o-calendar-days')
            ->emptyStateHeading(fn (): string => self::hasMailbox()
                ? __('filament/resources/meeting.empty_state.heading')
                : __('filament/pages/email-accounts.not_connected.meetings.heading'))
            ->emptyStateDescription(fn (): string => self::hasMailbox()
                ? __('filament/resources/meeting.empty_state.description')
                : __('filament/pages/email-accounts.not_connected.meetings.description'))
            ->emptyStateActions([
                ConfigureMailboxAction::make(),
            ]);
    }

    private static function hasMailbox(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();
        /** @var Team|null $team */
        $team = filament()->getTenant();

        return $user instanceof User && ConnectedAccount::hasConnectedFor($user, $team);
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListMeetings::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['connectedAccount', 'team', 'attendees.contact']);

        $user = auth()->user();

        if ($user instanceof User) {
            $query->withGlobalScope('visible', new VisibleMeetingScope($user));
        }

        return $query;
    }
}
