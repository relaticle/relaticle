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
use Relaticle\EmailIntegration\Models\MeetingAttendee;
use Relaticle\EmailIntegration\Models\Scopes\VisibleMeetingScope;
use Relaticle\EmailIntegration\Services\ListMeetingsForDay;
use Relaticle\EmailIntegration\Services\MeetingAttendeePresenter;
use Relaticle\EmailIntegration\Services\MeetingRespondentResolver;

/**
 * @property-read Collection<int, Meeting> $meetings
 * @property-read Action $connectGmailAction
 */
final class MeetingsHomeWidget extends Component implements HasActions, HasSchemas
{
    use HasConnectMailboxActions;
    use InteractsWithActions;
    use InteractsWithSchemas;

    public string $selectedDate = '';

    public ?string $viewingMeetingId = null;

    /** @var list<string> */
    public array $expandedMeetingIds = [];

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
        $this->expandedMeetingIds = [];
        unset($this->meetings);
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

    public function toggleAttendees(string $meetingId): void
    {
        if (in_array($meetingId, $this->expandedMeetingIds, true)) {
            $this->expandedMeetingIds = array_values(array_filter(
                $this->expandedMeetingIds,
                fn (string $id): bool => $id !== $meetingId,
            ));

            return;
        }

        $this->expandedMeetingIds[] = $meetingId;
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
     * @return list<array{name: string, email: string, avatar: string, is_organizer: bool, response_status: AttendeeResponseStatus|null}>
     */
    public function attendeeState(Meeting $meeting, int $limit = 0): array
    {
        $attendees = $meeting->attendees;
        $visible = $limit > 0 && ! in_array($meeting->getKey(), $this->expandedMeetingIds, true)
            ? $attendees->take($limit)
            : $attendees;

        return array_values($visible->values()->map(
            function (MeetingAttendee $attendee) use ($meeting): array {
                $attendee->setRelation('meeting', $meeting);

                return resolve(MeetingAttendeePresenter::class)->present($attendee);
            },
        )->all());
    }

    /**
     * The viewer's own RSVP, shown as the dot on the card. Colour alone carries
     * the meaning, so the label travels with it for screen readers and hover.
     */
    public function viewerResponseStatus(Meeting $meeting): AttendeeResponseStatus
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
        $this->expandedMeetingIds = [];
        unset($this->meetings);
    }

    private function resolveVisibleMeeting(string $meetingId): ?Meeting
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        return Meeting::query()
            ->withGlobalScope('visible', VisibleMeetingScope::personal($user))
            ->with(['team', 'attendees.contact', 'connectedAccount.user', 'people', 'companies', 'opportunities'])
            ->find($meetingId);
    }
}
