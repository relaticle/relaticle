<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Livewire;

use App\Models\User;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\ViewAction;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Filament\Infolists\MeetingDetailInfolist;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;
use Relaticle\EmailIntegration\Models\Scopes\VisibleMeetingScope;
use Relaticle\EmailIntegration\Services\ListMeetingsForDay;
use Relaticle\EmailIntegration\Services\MeetingAttendeePresenter;
use Relaticle\EmailIntegration\Services\MeetingRespondentResolver;

/**
 * @property-read Collection<int, Meeting> $meetings
 */
final class MeetingsHomeWidget extends Component implements HasActions, HasSchemas
{
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
        $this->selectedDate = Date::parse($this->selectedDate, $this->viewerTimezone())->subDay()->toDateString();
        $this->expandedMeetingIds = [];
        unset($this->meetings);
    }

    public function nextDay(): void
    {
        $this->selectedDate = Date::parse($this->selectedDate, $this->viewerTimezone())->addDay()->toDateString();
        $this->expandedMeetingIds = [];
        unset($this->meetings);
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

    public function dateLabel(): string
    {
        $selected = Date::parse($this->selectedDate, $this->viewerTimezone())->startOfDay();
        $today = Date::now($this->viewerTimezone())->startOfDay();
        $formatted = $selected->format('M j');

        return match ((int) $today->diffInDays($selected, false)) {
            0 => __('filament/pages/dashboard.meetings.date.today', ['date' => $formatted]),
            1 => __('filament/pages/dashboard.meetings.date.tomorrow', ['date' => $formatted]),
            -1 => __('filament/pages/dashboard.meetings.date.yesterday', ['date' => $formatted]),
            default => __('filament/pages/dashboard.meetings.date.other', [
                'weekday' => $selected->format('D'),
                'date' => $formatted,
            ]),
        };
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
     * @return list<array{name: string, avatar: string, is_organizer: bool, response_status: AttendeeResponseStatus|null}>
     */
    public function attendeeState(Meeting $meeting, int $limit = 0): array
    {
        $attendees = $meeting->attendees;
        $visible = $limit > 0 && ! in_array($meeting->getKey(), $this->expandedMeetingIds, true)
            ? $attendees->take($limit)
            : $attendees;

        return array_values($visible->values()->map(
            fn (MeetingAttendee $attendee): array => resolve(MeetingAttendeePresenter::class)->present($attendee),
        )->all());
    }

    public function viewerResponseColor(Meeting $meeting): string
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return 'gray';
        }

        return resolve(MeetingRespondentResolver::class)->viewerResponseStatus($user, $meeting)->getColor();
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

    private function resolveVisibleMeeting(string $meetingId): ?Meeting
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        return Meeting::query()
            ->withGlobalScope('visible', new VisibleMeetingScope($user))
            ->with(['team', 'attendees.contact', 'connectedAccount', 'people', 'companies', 'opportunities'])
            ->find($meetingId);
    }
}
