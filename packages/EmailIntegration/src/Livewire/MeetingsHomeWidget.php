<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Livewire;

use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Filament\Concerns\HasConnectMailboxActions;
use Relaticle\EmailIntegration\Filament\Infolists\MeetingDetailInfolist;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\Scopes\VisibleMeetingScope;
use Relaticle\EmailIntegration\Services\ListMeetingsForDay;
use Relaticle\EmailIntegration\Services\MailboxDisplayNameDirectory;
use Relaticle\EmailIntegration\Services\MailboxSyncTracker;
use Relaticle\EmailIntegration\Services\MeetingParticipantStackPresenter;
use Relaticle\EmailIntegration\Services\MeetingRespondentResolver;

/**
 * @property-read Collection<int, Meeting> $meetings
 * @property-read list<array{id: string, email: string, emailsImported: int, meetingsImported: int, percent: int, hasCalendar: bool, isInitialImport: bool}> $mailboxSyncRows
 * @property-read list<array{id: string, title: string, all_day: bool, participants: array{attendees: list<array{name: string, email: string, avatar: string, has_name: bool, is_organizer: bool, response_status: AttendeeResponseStatus|null}>, avatars: list<array{src: string, alt: string, has_name: bool, tooltip: string}>, overflow: int, overflow_tooltip: string|null}, response_status: AttendeeResponseStatus, time: array{start: string, end: string|null, range: string, datetime: string}, happening_now: bool}> $meetingCards
 * @property-read Action $connectGmailAction
 */
final class MeetingsHomeWidget extends Component implements HasActions, HasSchemas
{
    use HasConnectMailboxActions;
    use InteractsWithActions;
    use InteractsWithSchemas;

    private const int PAGE_SIZE = 4;

    public string $selectedDate = '';

    public ?string $viewingMeetingId = null;

    public int $visibleCount = self::PAGE_SIZE;

    public function mount(): void
    {
        $this->selectedDate = Date::now($this->viewerTimezone())->toDateString();
    }

    public function previousDay(): void
    {
        $this->goToDay(Date::parse($this->selectedDate, $this->viewerTimezone())->subDay());
    }

    public function nextDay(): void
    {
        $this->goToDay(Date::parse($this->selectedDate, $this->viewerTimezone())->addDay());
    }

    public function goToToday(): void
    {
        $this->goToDay(Date::now($this->viewerTimezone()));
    }

    public function goToNextDayWithMeetings(): void
    {
        $next = $this->nextDayWithMeetings();

        if ($next instanceof CarbonImmutable) {
            $this->goToDay($next);
        }
    }

    /**
     * Picking a date from the calendar writes straight to the bound property,
     * so the day reset hangs off the Livewire update hook rather than a method.
     */
    public function updatedSelectedDate(): void
    {
        $this->resetVisibleList();
    }

    public function loadMore(): void
    {
        if (! $this->hasMoreMeetings()) {
            return;
        }

        $this->visibleCount += self::PAGE_SIZE;
    }

    public function hasMoreMeetings(): bool
    {
        return $this->meetings->count() > $this->visibleCount;
    }

    public function datePickerSchema(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('selectedDate')
                ->hiddenLabel()
                ->native(false)
                ->live()
                ->closeOnDateSelection()
                ->displayFormat('M j')
                ->extraTriggerAttributes(['class' => 'fi-meetings-date-trigger'])
                ->label(fn (): string => __('filament/pages/dashboard.meetings.pick_date', [
                    'date' => $this->dateLabel(),
                ])),
        ])->statePath('');
    }

    public function isMailboxConnected(): bool
    {
        $user = auth()->user();
        $team = Filament::getTenant();

        if (! $user instanceof User) {
            return false;
        }

        return ConnectedAccount::hasActiveFor($user, $team instanceof Team ? $team : null);
    }

    public function isMailboxSyncing(): bool
    {
        return $this->mailboxSyncRows !== [];
    }

    public function shouldPollMailboxSync(): bool
    {
        return $this->isMailboxSyncing();
    }

    public function syncDisplayPercent(): int
    {
        $percents = array_map(
            fn (array $row): int => $row['percent'],
            $this->mailboxSyncRows,
        );

        return $percents === [] ? 0 : max($percents);
    }

    public function syncEmailsProcessed(): int
    {
        return array_sum(array_map(
            fn (array $row): int => $row['emailsImported'],
            $this->mailboxSyncRows,
        ));
    }

    public function syncMeetingsProcessed(): int
    {
        return array_sum(array_map(
            fn (array $row): int => $row['meetingsImported'],
            $this->mailboxSyncRows,
        ));
    }

    public function syncShowsMeetingsProcessed(): bool
    {
        return array_any(
            $this->mailboxSyncRows,
            fn (array $row): bool => $row['hasCalendar'],
        );
    }

    public function syncIsInitialImport(): bool
    {
        return array_any(
            $this->mailboxSyncRows,
            fn (array $row): bool => $row['isInitialImport'],
        );
    }

    public function syncShowsPercent(): bool
    {
        if ($this->syncIsInitialImport()) {
            return true;
        }

        return $this->syncDisplayPercent() > 0;
    }

    public function syncShowsProcessedCounts(): bool
    {
        if ($this->syncEmailsProcessed() > 0) {
            return true;
        }

        return $this->syncShowsMeetingsProcessed() && $this->syncMeetingsProcessed() > 0;
    }

    public function refreshMailboxSync(): void
    {
        unset($this->mailboxSyncRows, $this->meetings, $this->meetingCards);
    }

    /**
     * @return list<array{id: string, email: string, emailsImported: int, meetingsImported: int, percent: int, hasCalendar: bool, isInitialImport: bool}>
     */
    #[Computed]
    public function mailboxSyncRows(): array
    {
        $rows = [];

        foreach ($this->ownedAccounts() as $account) {
            if (! $account->showsSyncProgress()) {
                continue;
            }

            $isInitialImport = $account->isImportingHistory();

            $rows[] = [
                'id' => (string) $account->getKey(),
                'email' => $account->email_address,
                'emailsImported' => $isInitialImport
                    ? $account->syncEmailsProcessedCount()
                    : ($account->isEmailSyncing() ? MailboxSyncTracker::emailProcessedCount($account) : 0),
                'meetingsImported' => $isInitialImport
                    ? $account->syncMeetingsProcessedCount()
                    : ($account->isCalendarSyncing() ? MailboxSyncTracker::calendarProcessedCount($account) : 0),
                'percent' => $account->syncDisplayPercent(),
                'hasCalendar' => $account->hasCalendar(),
                'isInitialImport' => $isInitialImport,
            ];
        }

        return $rows;
    }

    public function nextDayWithMeetings(): ?CarbonImmutable
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        return resolve(ListMeetingsForDay::class)->nextDayWithMeetings(
            $user,
            Date::parse($this->selectedDate, $user->effectiveTimezone()),
        );
    }

    public function openMeeting(string $meetingId): void
    {
        $meeting = $this->resolveVisibleMeeting($meetingId);

        abort_unless($meeting instanceof Meeting, 404);

        $this->viewingMeetingId = $meeting->getKey();
        $this->mountAction('view');
    }

    public function viewAction(): ViewAction
    {
        return MeetingDetailInfolist::viewAction()
            ->record(fn (): ?Meeting => $this->viewingMeetingId === null
                ? null
                : $this->resolveVisibleMeeting($this->viewingMeetingId));
    }

    /**
     * The words before the date, e.g. "Today,". The date itself is drawn by the
     * calendar trigger from its own state, so it is never repeated here.
     */
    public function datePrefix(): string
    {
        $selected = Date::parse($this->selectedDate, $this->viewerTimezone())->startOfDay();
        $today = Date::now($this->viewerTimezone())->startOfDay();

        return match ((int) $today->diffInDays($selected, false)) {
            0 => __('filament/pages/dashboard.meetings.date.today'),
            1 => __('filament/pages/dashboard.meetings.date.tomorrow'),
            -1 => __('filament/pages/dashboard.meetings.date.yesterday'),
            default => __('filament/pages/dashboard.meetings.date.other', [
                'weekday' => $selected->format('D'),
            ]),
        };
    }

    /**
     * The prefix and the date together, for screen readers and the calendar's
     * accessible name. The visible header splits the two.
     */
    public function dateLabel(): string
    {
        $selected = Date::parse($this->selectedDate, $this->viewerTimezone())->startOfDay();

        return $this->datePrefix().' '.$selected->format('M j');
    }

    /**
     * @return Collection<int, Meeting>
     */
    #[Computed]
    public function meetings(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return new Collection;
        }

        return resolve(ListMeetingsForDay::class)->execute(
            $user,
            Date::parse($this->selectedDate, $user->effectiveTimezone()),
        );
    }

    /**
     * @return list<array{id: string, title: string, all_day: bool, participants: array{attendees: list<array{name: string, email: string, avatar: string, has_name: bool, is_organizer: bool, response_status: AttendeeResponseStatus|null}>, avatars: list<array{src: string, alt: string, has_name: bool, tooltip: string}>, overflow: int, overflow_tooltip: string|null}, response_status: AttendeeResponseStatus, time: array{start: string, end: string|null, range: string, datetime: string}, happening_now: bool}>
     */
    #[Computed]
    public function meetingCards(): array
    {
        $cards = [];

        foreach ($this->meetings as $meeting) {
            $cards[] = $this->meetingCard($meeting);
        }

        return $cards;
    }

    /**
     * @return array{id: string, title: string, all_day: bool, participants: array{attendees: list<array{name: string, email: string, avatar: string, has_name: bool, is_organizer: bool, response_status: AttendeeResponseStatus|null}>, avatars: list<array{src: string, alt: string, has_name: bool, tooltip: string}>, overflow: int, overflow_tooltip: string|null}, response_status: AttendeeResponseStatus, time: array{start: string, end: string|null, range: string, datetime: string}, happening_now: bool}
     */
    private function meetingCard(Meeting $meeting): array
    {
        return [
            'id' => (string) $meeting->getKey(),
            'title' => (string) $meeting->title,
            'all_day' => $meeting->all_day,
            'participants' => resolve(MeetingParticipantStackPresenter::class)->forMeeting($meeting),
            'response_status' => $this->viewerResponseStatus($meeting),
            'time' => $this->meetingTime($meeting),
            'happening_now' => $this->isHappeningNow($meeting),
        ];
    }

    /**
     * @return array{start: string, end: string|null, range: string, datetime: string}
     */
    private function meetingTime(Meeting $meeting): array
    {
        $timezone = $this->viewerTimezone();
        $start = $meeting->starts_at->timezone($timezone);

        if ($meeting->all_day) {
            $label = __('filament/pages/dashboard.meetings.all_day');

            return [
                'start' => $label,
                'end' => null,
                'range' => $label,
                'datetime' => $start->toIso8601String(),
            ];
        }

        $end = $meeting->ends_at->timezone($timezone);
        $startLabel = $start->format('g:i A');
        $endLabel = $end->equalTo($start) ? null : $end->format('g:i A');

        return [
            'start' => $startLabel,
            'end' => $endLabel,
            'range' => $endLabel === null
                ? $startLabel
                : __('filament/pages/dashboard.meetings.time_range', [
                    'start' => $startLabel,
                    'end' => $endLabel,
                ]),
            'datetime' => $start->toIso8601String(),
        ];
    }

    private function isHappeningNow(Meeting $meeting): bool
    {
        if ($meeting->all_day) {
            return false;
        }

        $now = Date::now($this->viewerTimezone());
        $start = $meeting->starts_at->timezone($this->viewerTimezone());
        $end = $meeting->ends_at->timezone($this->viewerTimezone());

        return $now->gte($start) && $now->lt($end);
    }

    /**
     * The viewer's own RSVP. Colour is paired with the status label so the
     * card never relies on the rail or dot alone.
     */
    private function viewerResponseStatus(Meeting $meeting): AttendeeResponseStatus
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return AttendeeResponseStatus::NEEDS_ACTION;
        }

        return resolve(MeetingRespondentResolver::class)->viewerResponseStatus($user, $meeting);
    }

    public function viewerTimezone(): string
    {
        $user = auth()->user();

        return $user instanceof User ? $user->effectiveTimezone() : (string) config('app.timezone');
    }

    public function render(): View
    {
        return view('email-integration::livewire.meetings-home-widget');
    }

    private function goToDay(CarbonImmutable $day): void
    {
        $this->selectedDate = $day->toDateString();
        $this->resetVisibleList();
    }

    private function resetVisibleList(): void
    {
        $this->visibleCount = self::PAGE_SIZE;
        unset($this->meetings, $this->meetingCards);
    }

    /**
     * @return Collection<int, ConnectedAccount>
     */
    private function ownedAccounts(): Collection
    {
        $user = auth()->user();
        $team = Filament::getTenant();

        if (! $user instanceof User || ! $team instanceof Team) {
            return new Collection;
        }

        return ConnectedAccount::query()->ownedBy($user, $team)->get();
    }

    private function resolveVisibleMeeting(string $meetingId): ?Meeting
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        $meeting = Meeting::query()
            ->withGlobalScope('visible', new VisibleMeetingScope($user))
            ->with(['team', 'attendees.contact', 'connectedAccount', 'people', 'companies', 'opportunities'])
            ->find($meetingId);

        if ($meeting instanceof Meeting) {
            resolve(MailboxDisplayNameDirectory::class)->primeFromMeetings([$meeting]);
        }

        return $meeting;
    }
}
