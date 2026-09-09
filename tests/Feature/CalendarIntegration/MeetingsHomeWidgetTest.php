<?php

declare(strict_types=1);

use App\Features\EmailIntegration;
use App\Filament\Pages\Dashboard;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Livewire\MeetingsHomeWidget;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;
use Relaticle\EmailIntegration\Services\ListMeetingsForDay;
use Relaticle\EmailIntegration\Services\MeetingAttendeePresenter;
use Relaticle\EmailIntegration\Services\TeamMemberDirectory;

mutates(MeetingsHomeWidget::class, ListMeetingsForDay::class, MeetingAttendeePresenter::class, TeamMemberDirectory::class, Dashboard::class);

beforeEach(function (): void {
    $this->travelTo(Date::parse('2026-09-09 15:00:00'));

    $this->user = User::factory()->withTeam()->create(['timezone' => 'UTC']);
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::withoutEvents(
        fn (): ConnectedAccount => ConnectedAccount::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
        ])
    );
});

it('does not render the meetings panel on home when email integration is off', function (): void {
    Feature::define(EmailIntegration::class, false);

    livewire(Dashboard::class)
        ->assertDontSee(__('filament/pages/dashboard.meetings.heading'))
        ->assertDontSee('data-testid="meetings-home"', escape: false);
});

it('renders the empty day copy on home', function (): void {
    livewire(MeetingsHomeWidget::class)
        ->assertSee(__('filament/pages/dashboard.meetings.heading'))
        ->assertSee(__('filament/pages/dashboard.meetings.empty.title'))
        ->assertSee(__('filament/pages/dashboard.meetings.empty.description'))
        ->assertSee(__('filament/pages/dashboard.meetings.date.today', ['date' => 'Sep 9']));
});

it('moves to tomorrow and yesterday from the date controls', function (): void {
    livewire(MeetingsHomeWidget::class)
        ->call('nextDay')
        ->assertSee(__('filament/pages/dashboard.meetings.date.tomorrow', ['date' => 'Sep 10']))
        ->call('previousDay')
        ->call('previousDay')
        ->assertSee(__('filament/pages/dashboard.meetings.date.yesterday', ['date' => 'Sep 8']));
});

it('shows the meetings panel on the dashboard above tasks', function (): void {
    $html = livewire(Dashboard::class)->html();

    expect($html)
        ->toContain(__('filament/pages/dashboard.meetings.heading'))
        ->toContain(__('filament/pages/dashboard.tasks.heading'));

    $meetingsPosition = strpos($html, __('filament/pages/dashboard.meetings.heading'));
    $tasksPosition = strpos($html, __('filament/pages/dashboard.tasks.heading'));

    expect($meetingsPosition)->toBeInt()
        ->and($tasksPosition)->toBeInt()
        ->and($meetingsPosition)->toBeLessThan($tasksPosition);
});

it('shows a meeting that starts on the selected local day', function (): void {
    Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Call',
        'starts_at' => Date::parse('2026-09-09 16:00:00'),
        'ends_at' => Date::parse('2026-09-09 17:00:00'),
        'all_day' => false,
    ]);

    livewire(MeetingsHomeWidget::class)
        ->assertSee('Call')
        ->assertSee('4:00 PM')
        ->assertDontSee(__('filament/pages/dashboard.meetings.empty.title'));
});

it('does not show a meeting that starts on another day', function (): void {
    Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Tomorrow standup',
        'starts_at' => Date::parse('2026-09-10 16:00:00'),
        'ends_at' => Date::parse('2026-09-10 17:00:00'),
        'all_day' => false,
    ]);

    livewire(MeetingsHomeWidget::class)
        ->assertDontSee('Tomorrow standup')
        ->assertSee(__('filament/pages/dashboard.meetings.empty.title'));
});

it('shows one copy when two calendars share an ical uid', function (): void {
    $teammate = User::factory()->create();
    $otherAccount = ConnectedAccount::withoutEvents(
        fn (): ConnectedAccount => ConnectedAccount::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $teammate->id,
        ])
    );
    $starts = Date::parse('2026-09-09 16:00:00');

    Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $otherAccount->id,
        'title' => 'Shared weekly',
        'ical_uid' => 'weekly@example.test',
        'starts_at' => $starts,
        'ends_at' => Date::parse('2026-09-09 17:00:00'),
    ]);
    Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Shared weekly',
        'ical_uid' => 'weekly@example.test',
        'starts_at' => $starts,
        'ends_at' => Date::parse('2026-09-09 17:00:00'),
    ]);

    $component = livewire(MeetingsHomeWidget::class)
        ->assertSee('Shared weekly');

    expect($component->instance()->meetings()->count())->toBe(1);
});

it('shows the linked person name and hides the email on the card', function (): void {
    $meeting = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Call',
        'starts_at' => Date::parse('2026-09-09 16:00:00'),
        'ends_at' => Date::parse('2026-09-09 17:00:00'),
    ]);
    $person = People::factory()->for($this->team)->create(['name' => 'Maya Chen']);

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->id,
        'name' => 'maya@example.test',
        'email_address' => 'maya@example.test',
        'contact_id' => $person->id,
        'is_organizer' => true,
    ]);

    livewire(MeetingsHomeWidget::class)
        ->assertSee('Maya Chen')
        ->assertSee(__('filament/resources/meeting.attendees.host'))
        ->assertDontSee('maya@example.test');
});

it('collapses guests after three and reveals the rest on show more', function (): void {
    $meeting = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Staff',
        'starts_at' => Date::parse('2026-09-09 16:00:00'),
        'ends_at' => Date::parse('2026-09-09 17:00:00'),
    ]);

    foreach (['Alice One', 'Bob Two', 'Cara Three', 'Drew Four'] as $name) {
        MeetingAttendee::factory()->create([
            'meeting_id' => $meeting->id,
            'name' => $name,
            'email_address' => strtolower(str_replace(' ', '.', $name)).'@example.test',
            'is_organizer' => $name === 'Alice One',
        ]);
    }

    livewire(MeetingsHomeWidget::class)
        ->assertSee('Alice One')
        ->assertSee('Bob Two')
        ->assertSee('Cara Three')
        ->assertDontSee('Drew Four')
        ->assertSee(__('filament/resources/meeting.attendees.show_more'))
        ->call('toggleAttendees', $meeting->id)
        ->assertSee('Drew Four')
        ->assertSee(__('filament/resources/meeting.attendees.show_less'));
});

it('opens the meeting slideover from the card', function (): void {
    $meeting = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Call',
        'starts_at' => Date::parse('2026-09-09 16:00:00'),
        'ends_at' => Date::parse('2026-09-09 17:00:00'),
    ]);

    livewire(MeetingsHomeWidget::class)
        ->call('openMeeting', $meeting->id)
        ->assertActionMounted('view')
        ->assertMountedActionModalSee('Call')
        ->assertMountedActionModalSee(__('filament/resources/meeting.view.heading'));
});
