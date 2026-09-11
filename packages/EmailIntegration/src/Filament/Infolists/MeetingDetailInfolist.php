<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Infolists;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Relaticle\EmailIntegration\Actions\LinkMeetingToRecordAction;
use Relaticle\EmailIntegration\Enums\MeetingLinkedRecordType;
use Relaticle\EmailIntegration\Filament\Actions\MeetingRsvpActions;
use Relaticle\EmailIntegration\Filament\Infolists\Entries\MeetingAttendeeEntry;
use Relaticle\EmailIntegration\Filament\Infolists\Entries\MeetingHeaderEntry;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;
use Relaticle\EmailIntegration\Services\MailboxDisplayNameDirectory;

final class MeetingDetailInfolist
{
    public static function viewAction(): ViewAction
    {
        return ViewAction::make()
            ->slideOver(false)
            ->modalHeading(__('filament/resources/meeting.view.heading'))
            ->modalWidth(Width::FiveExtraLarge)
            ->modalCancelAction(false)
            ->schema(fn (Schema $schema): Schema => self::configure($schema))
            ->registerModalActions([
                self::linkRecordsAction('linkRecords'),
                ...MeetingRsvpActions::make(),
            ]);
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components(function (Schema $schema): array {
            $record = $schema->getRecord();

            if ($record instanceof Meeting) {
                $record->loadMissing(['attendees.contact', 'people', 'companies', 'opportunities', 'connectedAccount.user']);
                $record->attendees->each(
                    fn (MeetingAttendee $attendee) => $attendee->setRelation('meeting', $record),
                );

                $viewer = auth()->user();

                if ($viewer instanceof User) {
                    resolve(MailboxDisplayNameDirectory::class)->primeFromMeetings($viewer, [$record]);
                }
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
                    ->color('primary')
                    ->icon(Heroicon::OutlinedLink)
                    ->url(fn (Meeting $record): ?string => $record->html_link, shouldOpenInNewTab: true)
                    ->visible(fn (Meeting $record): bool => filled($record->html_link)),
                TextEntry::make('location')
                    ->hiddenLabel()
                    ->icon(Heroicon::OutlinedMapPin)
                    ->visible(fn (Meeting $record): bool => filled($record->location)),
                Grid::make(5)
                    ->schema([
                        Section::make(__('filament/resources/meeting.sections.participants.heading'))
                            ->afterHeader([
                                TextEntry::make('attendees_badge')
                                    ->hiddenLabel()
                                    ->badge()
                                    ->state(fn (Meeting $record): int => $record->attendees->count()),
                            ])
                            ->schema([
                                RepeatableEntry::make('attendees')
                                    ->contained(false)
                                    ->hiddenLabel()
                                    ->schema([
                                        MeetingAttendeeEntry::make('attendee')->hiddenLabel(),
                                    ]),
                                TextEntry::make('attendees_empty')
                                    ->hiddenLabel()
                                    ->state(__('filament/resources/meeting.sections.participants.empty'))
                                    ->visible(fn (Meeting $record): bool => $record->attendees->isEmpty()),
                            ])
                            ->columnSpan(3),
                        Section::make(__('filament/resources/meeting.sections.linked_records.heading'))
                            ->afterLabel([
                                TextEntry::make('linked_badge')
                                    ->hiddenLabel()
                                    ->badge()
                                    ->state(fn (Meeting $record): int => self::linkedCount($record)),
                            ])
                            ->afterHeader([
                                self::linkRecordsAction('linkRecords')
                                    ->visible(fn (Meeting $record): bool => self::linkedCount($record) > 0),
                            ])
                            ->schema([
                                RepeatableEntry::make('linked_records')
                                    ->contained(false)
                                    ->hiddenLabel()
                                    ->state(fn (Meeting $record): array => self::linkedRecordsState($record))
                                    ->visible(fn (Meeting $record): bool => self::linkedCount($record) > 0)
                                    ->schema([
                                        Flex::make([
                                            TextEntry::make('name')
                                                ->hiddenLabel()
                                                ->weight(FontWeight::Medium)
                                                ->grow(),
                                            TextEntry::make('record_type')
                                                ->hiddenLabel()
                                                ->badge()
                                                ->formatStateUsing(fn (string $state): string => MeetingLinkedRecordType::from($state)->getLabel())
                                                ->icon(fn (string $state): Heroicon => MeetingLinkedRecordType::from($state)->getIcon())
                                                ->color(fn (string $state): string => MeetingLinkedRecordType::from($state)->getColor())
                                                ->grow(false)
                                                ->size(TextSize::Small),
                                        ])->alignment(Alignment::Between),
                                    ]),
                                EmptyState::make(__('filament/resources/meeting.sections.linked_records.empty.heading'))
                                    ->description(__('filament/resources/meeting.sections.linked_records.empty.description'))
                                    ->icon(Heroicon::OutlinedLink)
                                    ->contained(false)
                                    ->footer([
                                        self::linkRecordsAction('linkRecords')->button()->size(Size::ExtraSmall),
                                    ])
                                    ->visible(fn (Meeting $record): bool => self::linkedCount($record) === 0),
                            ])
                            ->compact(true)
                            ->columnSpan(2),
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

    /**
     * @return list<array{name: string, record_type: string}>
     */
    public static function linkedRecordsState(Meeting $meeting): array
    {
        $state = [];

        foreach ($meeting->people as $person) {
            $state[] = self::linkedRecordItem($person->name, MeetingLinkedRecordType::People);
        }

        foreach ($meeting->companies as $company) {
            $state[] = self::linkedRecordItem($company->name, MeetingLinkedRecordType::Company);
        }

        foreach ($meeting->opportunities as $opportunity) {
            $state[] = self::linkedRecordItem($opportunity->name, MeetingLinkedRecordType::Opportunity);
        }

        return $state;
    }

    /**
     * @return array{name: string, record_type: string}
     */
    private static function linkedRecordItem(string $name, MeetingLinkedRecordType $recordType): array
    {
        return [
            'name' => $name,
            'record_type' => $recordType->value,
        ];
    }

    public static function linkRecordsAction(string $name): Action
    {
        $action = Action::make($name)
            ->label(__('filament/resources/meeting.actions.link_records.label'))
            ->icon(Heroicon::Plus)
            ->schema(self::linkRecordFields())
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

        return $action->link();
    }

    /**
     * @return array<int, Select>
     */
    public static function linkRecordFields(): array
    {
        return [
            Select::make('target_type')
                ->label(__('filament/resources/meeting.fields.record_type.label'))
                ->options(self::linkTargetTypeOptions())
                ->required()
                ->live(),
            Select::make('target_id')
                ->label(fn (Get $get): string => self::linkTargetLabel((string) $get('target_type')))
                ->options(fn (Get $get): array => self::linkTargetOptions((string) $get('target_type')))
                ->searchable()
                ->required(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function linkTargetTypeOptions(): array
    {
        return MeetingLinkedRecordType::linkTargetTypeOptions();
    }

    private static function linkTargetLabel(string $type): string
    {
        return self::linkTargetTypeOptions()[$type] ?? __('filament/resources/meeting.fields.record.label');
    }

    /**
     * CRM models carry no global tenant scope, so every query here must be
     * constrained to the current tenant. Otherwise the option list (and the
     * resolveLinkTarget lookup below) would expose and link records from other teams.
     *
     * @return array<int|string, string>
     */
    private static function linkTargetOptions(string $type): array
    {
        $teamId = filament()->getTenant()?->getKey();

        return match (MeetingLinkedRecordType::tryFromLinkTargetType($type)) {
            MeetingLinkedRecordType::People => People::query()->where('team_id', $teamId)->pluck('name', 'id')->all(),
            MeetingLinkedRecordType::Company => Company::query()->where('team_id', $teamId)->pluck('name', 'id')->all(),
            MeetingLinkedRecordType::Opportunity => Opportunity::query()->where('team_id', $teamId)->pluck('name', 'id')->all(),
            default => [],
        };
    }

    private static function resolveLinkTarget(string $type, string $id): Model
    {
        $teamId = filament()->getTenant()?->getKey();

        return match (MeetingLinkedRecordType::fromLinkTargetType($type)) {
            MeetingLinkedRecordType::People => People::query()->where('team_id', $teamId)->findOrFail($id),
            MeetingLinkedRecordType::Company => Company::query()->where('team_id', $teamId)->findOrFail($id),
            MeetingLinkedRecordType::Opportunity => Opportunity::query()->where('team_id', $teamId)->findOrFail($id),
        };
    }
}
