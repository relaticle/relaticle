<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Resources;

use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconPosition;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Override;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Enums\CalendarEventStatus;
use Relaticle\EmailIntegration\Filament\Resources\MeetingResource\Pages\ListMeetings;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;

final class MeetingResource extends Resource
{
    protected static ?string $model = Meeting::class;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 3;

    protected static string|\UnitEnum|null $navigationGroup = null;

    public static function getNavigationGroup(): string
    {
        return __('filament/resources/meeting.navigation.group');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Meeting')->schema([
                TextEntry::make('title'),
                TextEntry::make('starts_at')->dateTime('M j, Y · g:i a'),
                TextEntry::make('ends_at')->dateTime('M j, Y · g:i a'),
                TextEntry::make('location')->default('—'),
                TextEntry::make('organizer_name')->label(__('filament/resources/meeting.infolist.organizer')),
            ]),
            Section::make('Attendees')->schema([
                RepeatableEntry::make('attendees')->schema([
                    TextEntry::make('name')->default(fn (MeetingAttendee $record): string => $record->email_address),
                    TextEntry::make('email_address')->label(__('filament/resources/meeting.infolist.email')),
                    TextEntry::make('response_status')->badge(),
                ]),
            ]),
            Section::make('Description')->schema([
                TextEntry::make('description')->html()->default('(no description)'),
            ]),
            Section::make('Link')->schema([
                TextEntry::make('html_link')
                    ->label(__('filament/resources/meeting.infolist.html_link'))
                    ->color('primary')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->iconPosition(IconPosition::After)
                    ->url(fn (Meeting $record): ?string => $record->html_link, shouldOpenInNewTab: true),
            ]),
        ]);
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
                    ->label(__('filament/resources/meeting.columns.time'))
                    ->dateTime('M j, Y · g:i a')
                    ->sortable(),
                TextColumn::make('organizer_name')
                    ->label(__('filament/resources/meeting.columns.organizer'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('attendees_count')
                    ->counts('attendees')
                    ->label(__('filament/resources/meeting.columns.attendees')),
                TextColumn::make('people_count')
                    ->counts('people')
                    ->label(__('filament/resources/meeting.columns.people'))
                    ->toggleable(),
                TextColumn::make('companies_count')
                    ->counts('companies')
                    ->label(__('filament/resources/meeting.columns.companies'))
                    ->toggleable(),
                TextColumn::make('opportunities_count')
                    ->counts('opportunities')
                    ->label(__('filament/resources/meeting.columns.opportunities'))
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('response_status')
                    ->label(__('filament/resources/meeting.columns.rsvp'))
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
                    ->label(__('filament/resources/meeting.filters.rsvp'))
                    ->options(AttendeeResponseStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
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
        return parent::getEloquentQuery()
            ->with(['connectedAccount', 'team']);
    }
}
