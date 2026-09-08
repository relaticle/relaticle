<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Infolists;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Relaticle\EmailIntegration\Actions\LinkMeetingToRecordAction;
use Relaticle\EmailIntegration\Filament\Actions\MeetingRsvpActions;
use Relaticle\EmailIntegration\Filament\Infolists\Entries\MeetingAttendeeEntry;
use Relaticle\EmailIntegration\Filament\Infolists\Entries\MeetingHeaderEntry;
use Relaticle\EmailIntegration\Filament\Infolists\Entries\MeetingLinkedRecordsEntry;
use Relaticle\EmailIntegration\Models\Meeting;

final class MeetingDetailInfolist
{
    public static function viewAction(): ViewAction
    {
        return ViewAction::make()
            ->modalHeading(__('filament/resources/meeting.view.heading'))
            ->modalWidth(Width::Large)
            ->schema(fn (Schema $schema): Schema => self::configure($schema))
            ->registerModalActions([
                self::linkRecordsAction('linkRecordsEmpty'),
                ...MeetingRsvpActions::make(),
            ]);
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components(function (Schema $schema): array {
            $record = $schema->getRecord();

            if ($record instanceof Meeting) {
                $record->loadMissing(['attendees.contact', 'people', 'companies', 'opportunities', 'connectedAccount']);
            }

            $rsvpGroup = MeetingRsvpActions::group();

            if ($record instanceof Meeting) {
                $rsvpGroup->record($record);
            }

            return [
                Flex::make([
                    MeetingHeaderEntry::make('header')
                        ->hiddenLabel()
                        ->grow(),
                    $rsvpGroup,
                ])->verticallyAlignCenter(),
                TextEntry::make('time_row')
                    ->hiddenLabel()
                    ->icon(Heroicon::OutlinedClock)
                    ->state(function (Meeting $record): string {
                        if ($record->all_day) {
                            return $record->starts_at->format('M j').' ('.__('filament/resources/meeting.time.all_day').')';
                        }

                        return $record->starts_at->format('M j').'  '.$record->starts_at->format('g:i A').' → '.$record->ends_at->format('g:i A').' ('.self::compactDuration($record).')';
                    }),
                TextEntry::make('html_link')
                    ->hiddenLabel()
                    ->icon(Heroicon::OutlinedLink)
                    ->url(fn (Meeting $record): ?string => $record->html_link, shouldOpenInNewTab: true)
                    ->visible(fn (Meeting $record): bool => filled($record->html_link)),
                TextEntry::make('location')
                    ->hiddenLabel()
                    ->icon(Heroicon::OutlinedMapPin)
                    ->visible(fn (Meeting $record): bool => filled($record->location)),
                Section::make(__('filament/resources/meeting.sections.participants.heading'))
                    ->afterHeader([
                        TextEntry::make('attendees_badge')
                            ->hiddenLabel()
                            ->badge()
                            ->state(fn (Meeting $record): int => $record->attendees->count()),
                    ])
                    ->schema([
                        RepeatableEntry::make('attendees')
                            ->hiddenLabel()
                            ->schema([
                                MeetingAttendeeEntry::make('attendee')->hiddenLabel(),
                            ]),
                        TextEntry::make('attendees_empty')
                            ->hiddenLabel()
                            ->state(__('filament/resources/meeting.sections.participants.empty'))
                            ->visible(fn (Meeting $record): bool => $record->attendees->isEmpty()),
                    ]),
                Section::make(__('filament/resources/meeting.sections.linked_records.heading'))
                    ->afterHeader([
                        TextEntry::make('linked_badge')
                            ->hiddenLabel()
                            ->badge()
                            ->state(fn (Meeting $record): int => self::linkedCount($record)),
                    ])
                    ->headerActions([
                        self::linkRecordsAction('linkRecords')->iconButton(),
                    ])
                    ->schema([
                        MeetingLinkedRecordsEntry::make('linked_records')
                            ->hiddenLabel()
                            ->visible(fn (Meeting $record): bool => self::linkedCount($record) > 0),
                        self::linkRecordsAction('linkRecordsEmpty')
                            ->visible(fn (Meeting $record): bool => self::linkedCount($record) === 0),
                    ]),
                Section::make(__('filament/resources/meeting.sections.description.heading'))
                    ->schema([
                        TextEntry::make('description')->hiddenLabel()->html(),
                    ])
                    ->visible(fn (Meeting $record): bool => filled($record->description)),
            ];
        });
    }

    public static function compactDuration(Meeting $meeting): string
    {
        $minutes = (int) $meeting->starts_at->diffInMinutes($meeting->ends_at);

        if ($minutes < 60) {
            return $minutes.'m';
        }

        $hours = intdiv($minutes, 60);
        $remainder = $minutes % 60;

        if ($remainder === 0) {
            return $hours.'h';
        }

        return $hours.'h '.$remainder.'m';
    }

    public static function linkedCount(Meeting $meeting): int
    {
        return $meeting->people->count() + $meeting->companies->count() + $meeting->opportunities->count();
    }

    public static function linkRecordsAction(string $name): Action
    {
        return Action::make($name)
            ->label(__('filament/resources/meeting.actions.link_records.label'))
            ->icon(Heroicon::Plus)
            ->schema([
                Select::make('target_type')
                    ->options([
                        'People' => __('filament/resources/meeting.linked_record_types.people'),
                        'Company' => __('filament/resources/meeting.linked_record_types.companies'),
                        'Opportunity' => __('filament/resources/meeting.linked_record_types.opportunities'),
                    ])
                    ->required()
                    ->live(),
                Select::make('target_id')
                    ->options(function (Get $get): array {
                        $teamId = filament()->getTenant()?->getKey();

                        return match ((string) $get('target_type')) {
                            'People' => People::query()->where('team_id', $teamId)->pluck('name', 'id')->all(),
                            'Company' => Company::query()->where('team_id', $teamId)->pluck('name', 'id')->all(),
                            'Opportunity' => Opportunity::query()->where('team_id', $teamId)->pluck('name', 'id')->all(),
                            default => [],
                        };
                    })
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data, Meeting $record): void {
                $target = self::resolveLinkTarget(
                    (string) $data['target_type'],
                    (string) $data['target_id'],
                );

                resolve(LinkMeetingToRecordAction::class)->execute($record, $target);

                Notification::make()
                    ->success()
                    ->title(__('filament/relation-managers/meetings.notifications.linked.title'))
                    ->send();
            });
    }

    private static function resolveLinkTarget(string $type, string $id): Model
    {
        $teamId = filament()->getTenant()?->getKey();

        return match ($type) {
            'People' => People::query()->where('team_id', $teamId)->findOrFail($id),
            'Company' => Company::query()->where('team_id', $teamId)->findOrFail($id),
            'Opportunity' => Opportunity::query()->where('team_id', $teamId)->findOrFail($id),
            default => throw new InvalidArgumentException('Unsupported type: '.$type),
        };
    }
}
