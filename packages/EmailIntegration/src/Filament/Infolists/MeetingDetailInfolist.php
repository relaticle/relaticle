<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Infolists;

use App\Enums\WorkspaceCapability;
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
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Relaticle\EmailIntegration\Actions\LinkMeetingToRecordAction;
use Relaticle\EmailIntegration\Actions\UnlinkMeetingFromRecordAction;
use Relaticle\EmailIntegration\Enums\MeetingLinkedRecordType;
use Relaticle\EmailIntegration\Filament\Actions\MeetingRsvpActions;
use Relaticle\EmailIntegration\Filament\Infolists\Entries\MeetingAttendeeEntry;
use Relaticle\EmailIntegration\Filament\Infolists\Entries\MeetingHeaderEntry;
use Relaticle\EmailIntegration\Filament\Infolists\Entries\MeetingLinkedRecordsEntry;
use Relaticle\EmailIntegration\Filament\Infolists\Entries\MeetingTimeEntry;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;
use Relaticle\EmailIntegration\Services\MailboxDisplayNameDirectory;
use Relaticle\EmailIntegration\Services\MeetingTemporalState;

final class MeetingDetailInfolist
{
    public static function viewAction(): ViewAction
    {
        return ViewAction::make()
            ->slideOver(false)
            ->modalHeading(fn (?Meeting $record): string|Htmlable => self::heading($record))
            ->modalAutofocus(false)
            ->modalWidth(Width::FourExtraLarge)
            ->modalCancelAction(false)
            ->schema(fn (Schema $schema): Schema => self::configure($schema))
            ->registerModalActions([
                self::linkRecordsAction('linkRecords'),
                self::unlinkRecordAction(),
                ...MeetingRsvpActions::make(),
            ]);
    }

    private static function heading(?Meeting $meeting): string|Htmlable
    {
        if (! $meeting instanceof Meeting) {
            return __('filament/resources/meeting.view.heading');
        }

        if (! resolve(MeetingTemporalState::class)->isPast($meeting, self::authUser()->effectiveTimezone())) {
            return $meeting->title;
        }

        return new HtmlString('<span class="text-gray-400 line-through dark:text-gray-500">'.e($meeting->title).'</span>');
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components(function (Schema $schema): array {
                $record = $schema->getRecord();

                if ($record instanceof Meeting) {
                    $record->loadMissing(['attendees.contact', 'people', 'companies', 'opportunities', 'connectedAccount.user']);
                    $record->setRelation('attendees', $record->attendees->sortByDesc('is_organizer')->values());
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
                        Flex::make([self::joinAction(), $rsvpGroup])->grow(false),
                    ])
                        ->from('sm')
                        ->verticallyAlignCenter()
                        ->columnSpanFull(),
                    MeetingTimeEntry::make('time_row')
                        ->hiddenLabel()
                        ->columnSpanFull(),
                    Grid::make(5)
                        ->columnSpanFull()
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
                                    MeetingLinkedRecordsEntry::make('linked_records')
                                        ->hiddenLabel()
                                        ->registerActions([self::unlinkRecordAction()])
                                        ->visible(fn (Meeting $record): bool => self::linkedCount($record) > 0),
                                    EmptyState::make(__('filament/resources/meeting.sections.linked_records.empty.heading'))
                                        ->description(__('filament/resources/meeting.sections.linked_records.empty.description'))
                                        ->icon('lucide-link')
                                        ->contained(false)
                                        ->footer([
                                            self::linkRecordsAction('linkRecords')->button()->size(Size::ExtraSmall),
                                        ])
                                        ->visible(fn (Meeting $record): bool => self::linkedCount($record) === 0),
                                ])
                                ->compact(true)
                                ->columnSpan(2),
                        ]),
                    self::descriptionSection(),
                ];
            });
    }

    public static function linkedCount(Meeting $meeting): int
    {
        return $meeting->people->count() + $meeting->companies->count() + $meeting->opportunities->count();
    }

    private static function descriptionSection(): Section
    {
        return Section::make(__('filament/resources/meeting.sections.description.heading'))
            ->schema([
                TextEntry::make('description')
                    ->hiddenLabel()
                    ->formatStateUsing(fn (string $state): string => self::descriptionHtml($state))
                    ->html()
                    ->prose(),
            ])
            ->columnSpanFull()
            ->visible(fn (Meeting $record): bool => filled($record->description));
    }

    private static function descriptionHtml(string $description): string
    {
        if ($description !== strip_tags($description)) {
            return $description;
        }

        $lines = array_filter(
            preg_split('/\R/', $description) ?: [],
            static fn (string $line): bool => preg_match('/^[\s_\-=*]{5,}$/', $line) !== 1,
        );

        return nl2br(e(trim(implode("\n", $lines))));
    }

    public static function linkRecordsAction(string $name): Action
    {
        $action = Action::make($name)
            ->label(__('filament/resources/meeting.actions.link_records.label'))
            ->icon('lucide-plus')
            ->authorize(fn (Meeting $record): bool => self::authUser()->hasWorkspaceCapability($record->workspace_id, WorkspaceCapability::RecordsUpdate))
            ->overlayParentActions()
            ->schema(self::linkRecordFields())
            ->action(function (array $data, Meeting $record): void {
                $target = self::resolveLinkTarget(
                    (string) $data['target_type'],
                    (string) $data['target_id'],
                );

                resolve(LinkMeetingToRecordAction::class)->execute(self::authUser(), $record, $target);

                Notification::make()
                    ->success()
                    ->title(__('filament/relation-managers/meetings.notifications.linked.title'))
                    ->send();
            });

        return $action->link();
    }

    public static function unlinkRecordAction(): Action
    {
        return Action::make('unlinkRecord')
            ->label(fn (array $arguments): string => __('filament/resources/meeting.actions.unlink_record.label', ['name' => $arguments['name'] ?? '']))
            ->icon('lucide-unlink')
            ->iconButton()
            ->color('gray')
            ->size(Size::Small)
            ->authorize(fn (Meeting $record): bool => self::authUser()->hasWorkspaceCapability($record->workspace_id, WorkspaceCapability::RecordsUpdate))
            ->requiresConfirmation()
            ->overlayParentActions()
            ->modalSubmitAction(fn (Action $action): Action => $action
                ->label(__('filament/resources/meeting.actions.unlink_record.submit'))
                ->color('danger'))
            ->modalHeading(fn (array $arguments): string => __('filament/resources/meeting.actions.unlink_record.heading', ['name' => $arguments['name'] ?? '']))
            ->modalDescription(__('filament/resources/meeting.actions.unlink_record.description'))
            ->action(function (array $arguments, Meeting $record): void {
                $target = self::resolveLinkTarget((string) $arguments['type'], (string) $arguments['id']);

                resolve(UnlinkMeetingFromRecordAction::class)->execute(self::authUser(), $record, $target);

                $record->load(['people', 'companies', 'opportunities']);

                Notification::make()
                    ->success()
                    ->title(__('filament/relation-managers/meetings.notifications.unlinked.title'))
                    ->send();
            });
    }

    public static function joinAction(): Action
    {
        return Action::make('joinMeeting')
            ->label(__('filament/resources/meeting.actions.join.label'))
            ->icon('lucide-video')
            ->button()
            ->size(Size::ExtraSmall)
            ->url(fn (Meeting $record): ?string => $record->join_url, shouldOpenInNewTab: true)
            ->visible(fn (Meeting $record): bool => str_starts_with((string) $record->join_url, 'https://')
                && ! resolve(MeetingTemporalState::class)->isPast($record, self::authUser()->effectiveTimezone()));
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
     * resolveLinkTarget lookup below) would expose and link records from other workspaces.
     *
     * @return array<int|string, string>
     */
    private static function linkTargetOptions(string $type): array
    {
        $workspaceId = filament()->getTenant()?->getKey();

        return match (MeetingLinkedRecordType::tryFromLinkTargetType($type)) {
            MeetingLinkedRecordType::People => People::query()->where('workspace_id', $workspaceId)->pluck('name', 'id')->all(),
            MeetingLinkedRecordType::Company => Company::query()->where('workspace_id', $workspaceId)->pluck('name', 'id')->all(),
            MeetingLinkedRecordType::Opportunity => Opportunity::query()->where('workspace_id', $workspaceId)->pluck('name', 'id')->all(),
            default => [],
        };
    }

    private static function resolveLinkTarget(string $type, string $id): Model
    {
        $workspaceId = filament()->getTenant()?->getKey();

        return match (MeetingLinkedRecordType::fromLinkTargetType($type)) {
            MeetingLinkedRecordType::People => People::query()->where('workspace_id', $workspaceId)->findOrFail($id),
            MeetingLinkedRecordType::Company => Company::query()->where('workspace_id', $workspaceId)->findOrFail($id),
            MeetingLinkedRecordType::Opportunity => Opportunity::query()->where('workspace_id', $workspaceId)->findOrFail($id),
        };
    }

    private static function authUser(): User
    {
        /** @var User */
        return auth()->user();
    }
}
