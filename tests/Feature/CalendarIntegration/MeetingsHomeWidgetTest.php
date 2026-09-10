<?php

declare(strict_types=1);

use App\Features\EmailIntegration;
use App\Filament\Pages\Dashboard;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Filament\Concerns\HasConnectMailboxActions;
use Relaticle\EmailIntegration\Livewire\MeetingsHomeWidget;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;
use Relaticle\EmailIntegration\Models\Scopes\VisibleMeetingScope;
use Relaticle\EmailIntegration\Services\ListMeetingsForDay;
use Relaticle\EmailIntegration\Services\MailboxDisplayNameDirectory;
use Relaticle\EmailIntegration\Services\MailboxSyncTracker;
use Relaticle\EmailIntegration\Services\MeetingAttendeePresenter;
use Relaticle\EmailIntegration\Services\TeamMemberDirectory;

mutates(MeetingsHomeWidget::class, ListMeetingsForDay::class, MeetingAttendeePresenter::class, TeamMemberDirectory::class, MailboxDisplayNameDirectory::class, Dashboard::class, HasConnectMailboxActions::class);

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
            'sync_cursor' => 'done',
            'calendar_sync_cursor' => 'done',
            'last_synced_at' => now(),
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
        ->assertSee(__('filament/pages/dashboard.meetings.date.today'));
});

it('shows mailbox sync progress inside the meetings section', function (): void {
    $this->account->update([
        'capabilities' => ['email' => true, 'calendar' => true],
        'sync_cursor' => null,
        'calendar_sync_cursor' => null,
        'initial_sync_imported' => 12,
        'initial_sync_estimated' => 40,
    ]);

    livewire(MeetingsHomeWidget::class)
        ->assertSee('data-testid="meetings-mailbox-sync"', escape: false)
        ->assertSee(__('filament/pages/dashboard.meetings.syncing.title_with_percent', ['percent' => 30]))
        ->assertSee(__('filament/pages/dashboard.meetings.syncing.description_initial'))
        ->assertSee(trans_choice('filament/pages/dashboard.meetings.syncing.emails_processed', 12, ['count' => 12]))
        ->assertDontSee(trans_choice('filament/pages/dashboard.meetings.syncing.meetings_processed', 0, ['count' => 0]))
        ->assertSee('role="progressbar"', false)
        ->assertSee('aria-valuenow="30"', false)
        ->assertDontSee(__('filament/pages/dashboard.meetings.empty.title'));
});

it('shows mailbox sync progress during email-only history import', function (): void {
    $this->account->update([
        'capabilities' => ['email' => true, 'calendar' => false],
        'sync_cursor' => null,
        'initial_sync_imported' => 0,
        'initial_sync_estimated' => 100,
    ]);

    livewire(MeetingsHomeWidget::class)
        ->assertSee('data-testid="meetings-mailbox-sync"', escape: false)
        ->assertSee(__('filament/pages/dashboard.meetings.syncing.title_with_percent', ['percent' => 0]))
        ->assertDontSee(__('filament/pages/dashboard.meetings.empty.title'));
});

it('hides meetings while mailbox sync is in progress', function (): void {
    Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Board review',
        'starts_at' => Date::parse('2026-09-09 16:00:00'),
        'ends_at' => Date::parse('2026-09-09 17:00:00'),
    ]);

    $this->account->update([
        'sync_cursor' => null,
        'initial_sync_imported' => 8,
        'initial_sync_estimated' => 100,
    ]);

    livewire(MeetingsHomeWidget::class)
        ->assertSee('data-testid="meetings-mailbox-sync"', escape: false)
        ->assertDontSee('Board review')
        ->assertDontSee(__('filament/pages/dashboard.meetings.empty.title'));
});

it('hides meetings while calendar sync is in progress', function (): void {
    Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Board review',
        'starts_at' => Date::parse('2026-09-09 16:00:00'),
        'ends_at' => Date::parse('2026-09-09 17:00:00'),
    ]);

    $this->account->update([
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_sync_cursor' => null,
    ]);

    livewire(MeetingsHomeWidget::class)
        ->assertSee('data-testid="meetings-mailbox-sync"', escape: false)
        ->assertDontSee('Board review')
        ->assertDontSee(__('filament/pages/dashboard.meetings.empty.title'));
});

it('shows reconnect sync progress with percent and new item counts', function (): void {
    $this->account->update([
        'capabilities' => ['email' => true, 'calendar' => true],
        'sync_cursor' => 'done',
        'calendar_sync_cursor' => 'done',
    ]);

    MailboxSyncTracker::markEmailStarted($this->account);
    MailboxSyncTracker::setEmailRunTotal($this->account, 10);
    MailboxSyncTracker::bumpEmailProcessed($this->account);
    MailboxSyncTracker::bumpEmailProcessed($this->account);

    MailboxSyncTracker::markCalendarStarted($this->account);
    MailboxSyncTracker::setCalendarRunTotal($this->account, 4);
    MailboxSyncTracker::bumpCalendarProcessed($this->account);

    livewire(MeetingsHomeWidget::class)
        ->assertSee(__('filament/pages/dashboard.meetings.syncing.title_with_percent', ['percent' => 25]))
        ->assertSee(__('filament/pages/dashboard.meetings.syncing.description_update'))
        ->assertSee(trans_choice('filament/pages/dashboard.meetings.syncing.emails_updated', 2, ['count' => 2]))
        ->assertSee(trans_choice('filament/pages/dashboard.meetings.syncing.meetings_updated', 1, ['count' => 1]))
        ->assertSee('aria-valuenow="25"', false);
});

it('hides zero counts during reconnect sync before new items arrive', function (): void {
    $this->account->update([
        'capabilities' => ['email' => true, 'calendar' => true],
        'sync_cursor' => 'done',
        'calendar_sync_cursor' => 'done',
        'initial_sync_imported' => 643,
        'initial_calendar_sync_imported' => 120,
    ]);

    MailboxSyncTracker::markEmailStarted($this->account);
    MailboxSyncTracker::markCalendarStarted($this->account);

    livewire(MeetingsHomeWidget::class)
        ->assertSee(__('filament/pages/dashboard.meetings.syncing.title'))
        ->assertSee(__('filament/pages/dashboard.meetings.syncing.description_update'))
        ->assertDontSee(trans_choice('filament/pages/dashboard.meetings.syncing.emails_processed', 643, ['count' => 643]))
        ->assertDontSee(trans_choice('filament/pages/dashboard.meetings.syncing.emails_updated', 0, ['count' => 0]))
        ->assertDontSee('aria-valuenow=', false);
});

it('shows meetings after calendar sync finishes', function (): void {
    $this->account->update([
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_sync_cursor' => 'done',
    ]);

    Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Board review',
        'starts_at' => Date::parse('2026-09-09 16:00:00'),
        'ends_at' => Date::parse('2026-09-09 17:00:00'),
    ]);

    MailboxSyncTracker::markCalendarStarted($this->account);

    $component = livewire(MeetingsHomeWidget::class)
        ->assertSee('data-testid="meetings-mailbox-sync"', escape: false)
        ->assertDontSee('Board review');

    MailboxSyncTracker::markCalendarFinished($this->account);

    $component->call('refreshMailboxSync')
        ->assertDontSee('data-testid="meetings-mailbox-sync"', escape: false)
        ->assertSee('Board review');
});

it('shows mailbox sync progress on the dashboard inside meetings', function (): void {
    $this->account->update([
        'capabilities' => ['email' => true, 'calendar' => true],
        'sync_cursor' => null,
        'calendar_sync_cursor' => null,
        'initial_sync_imported' => 4,
        'initial_sync_estimated' => 100,
    ]);

    livewire(Dashboard::class)
        ->assertSee(__('filament/pages/dashboard.meetings.syncing.title_with_percent', ['percent' => 4]))
        ->assertSee('data-testid="meetings-mailbox-sync"', escape: false)
        ->assertDontSee('data-mailbox-import="home"', false);
});

it('moves to tomorrow and yesterday from the date controls', function (): void {
    $component = livewire(MeetingsHomeWidget::class)
        ->call('nextDay')
        ->assertSee(__('filament/pages/dashboard.meetings.date.tomorrow'));

    expect($component->instance()->selectedDate)->toBe('2026-09-10');

    $component
        ->call('previousDay')
        ->call('previousDay')
        ->assertSee(__('filament/pages/dashboard.meetings.date.yesterday'));

    expect($component->instance()->selectedDate)->toBe('2026-09-08');
});

it('returns to today from the overflow menu', function (): void {
    $component = livewire(MeetingsHomeWidget::class)
        ->call('previousDay')
        ->call('previousDay')
        ->call('goToToday');

    expect($component->instance()->selectedDate)->toBe('2026-09-09');
});

it('loads the day chosen in the calendar', function (): void {
    Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Board review',
        'starts_at' => Date::parse('2026-09-21 16:00:00'),
        'ends_at' => Date::parse('2026-09-21 17:00:00'),
    ]);

    livewire(MeetingsHomeWidget::class)
        ->assertDontSee('Board review')
        ->set('selectedDate', '2026-09-21')
        ->assertSee('Board review')
        ->assertDontSee(__('filament/pages/dashboard.meetings.empty.title'));
});

it('jumps to the next day that has a meeting', function (): void {
    Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Renewal call',
        'starts_at' => Date::parse('2026-09-12 16:00:00'),
        'ends_at' => Date::parse('2026-09-12 17:00:00'),
    ]);

    $component = livewire(MeetingsHomeWidget::class)
        ->assertSee(__('filament/pages/dashboard.meetings.empty.next_with_meetings'))
        ->call('goToNextDayWithMeetings')
        ->assertSee('Renewal call');

    expect($component->instance()->selectedDate)->toBe('2026-09-12');
});

it('hides the jump button when no later meeting exists', function (): void {
    Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Past sync',
        'starts_at' => Date::parse('2026-09-01 16:00:00'),
        'ends_at' => Date::parse('2026-09-01 17:00:00'),
    ]);

    livewire(MeetingsHomeWidget::class)
        ->assertSee(__('filament/pages/dashboard.meetings.empty.title'))
        ->assertDontSee(__('filament/pages/dashboard.meetings.empty.next_with_meetings'));
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
        ->assertSee('5:00 PM')
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

it('asks the user to sync a calendar when no mailbox is connected', function (): void {
    $this->account->delete();

    livewire(MeetingsHomeWidget::class)
        ->assertSee(__('filament/pages/dashboard.meetings.disconnected.title'))
        ->assertSee(__('filament/pages/dashboard.meetings.disconnected.description'))
        ->assertDontSee(__('filament/pages/dashboard.meetings.empty.title'))
        ->assertActionVisible('connectGmail')
        ->assertActionHidden('connectAzure')
        ->assertActionHasUrl(
            TestAction::make('connectGmail'),
            route('email-accounts.redirect', ['provider' => 'gmail']),
        );
});

it('still asks to sync when the only mailbox is disconnected', function (): void {
    $this->account->delete();

    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->disconnected()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    livewire(MeetingsHomeWidget::class)
        ->assertSee(__('filament/pages/dashboard.meetings.disconnected.title'))
        ->assertActionVisible('connectGmail');
});

it('does not treat a teammate mailbox as this user calendar', function (): void {
    $this->account->delete();
    $teammate = User::factory()->create(['current_team_id' => $this->team->id]);

    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $teammate->id,
    ]));

    livewire(MeetingsHomeWidget::class)
        ->assertSee(__('filament/pages/dashboard.meetings.disconnected.title'));
});

it('drops the sync prompt once a mailbox is connected', function (): void {
    livewire(MeetingsHomeWidget::class)
        ->assertDontSee(__('filament/pages/dashboard.meetings.disconnected.title'))
        ->assertSee(__('filament/pages/dashboard.meetings.empty.title'));
});

it('colours the row dot by the viewer RSVP without showing the label', function (string $status, string $expectedClass, string $unexpectedLabel): void {
    Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Call',
        'starts_at' => Date::parse('2026-09-09 16:00:00'),
        'ends_at' => Date::parse('2026-09-09 17:00:00'),
        'response_status' => $status,
    ]);

    livewire(MeetingsHomeWidget::class)
        ->assertSee($expectedClass, escape: false)
        ->assertDontSee($unexpectedLabel);
})->with([
    'accepted is green' => ['accepted', 'bg-success-500', 'Accepted'],
    'declined is red' => ['declined', 'bg-danger-500', 'Declined'],
    'maybe is orange' => ['tentative', 'bg-warning-500', 'Maybe'],
    'pending is grey' => ['needsAction', 'bg-gray-400', 'Pending'],
]);

it('hides attendee avatars on the collapsed row but keeps the accordion panel', function (): void {
    $meeting = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Staff call',
        'starts_at' => Date::parse('2026-09-09 16:00:00'),
        'ends_at' => Date::parse('2026-09-09 17:00:00'),
    ]);

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->id,
        'name' => 'Maya Chen',
        'email_address' => 'maya@example.test',
    ]);

    $html = html_entity_decode(livewire(MeetingsHomeWidget::class)->html());

    expect($html)
        ->toContain('Staff call')
        ->toContain('data-testid="meeting-card-participants"')
        ->toContain('Maya Chen')
        ->not->toContain('data-testid="meeting-card-participants-preview"')
        ->not->toContain('more_participants');
});

it('marks an in-progress meeting as happening now', function (): void {
    $this->travelTo(Date::parse('2026-09-09 16:30:00'));

    Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Call',
        'starts_at' => Date::parse('2026-09-09 16:00:00'),
        'ends_at' => Date::parse('2026-09-09 17:00:00'),
        'all_day' => false,
    ]);

    livewire(MeetingsHomeWidget::class)
        ->assertSee(__('filament/pages/dashboard.meetings.happening_now'));
});

it('strikes through the title when a meeting is in the past', function (): void {
    Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Morning sync',
        'starts_at' => Date::parse('2026-09-09 10:00:00'),
        'ends_at' => Date::parse('2026-09-09 10:30:00'),
        'all_day' => false,
    ]);

    Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Afternoon review',
        'starts_at' => Date::parse('2026-09-09 16:00:00'),
        'ends_at' => Date::parse('2026-09-09 17:00:00'),
        'all_day' => false,
    ]);

    $html = html_entity_decode(livewire(MeetingsHomeWidget::class)->html());

    expect($html)
        ->toContain('Morning sync')
        ->toContain('Afternoon review')
        ->toMatch('/Morning sync.*line-through/s')
        ->not->toMatch('/Afternoon review.*line-through/s');
});

it('does not mark a later meeting as happening now', function (): void {
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
        ->assertDontSee(__('filament/pages/dashboard.meetings.happening_now'));
});

it('opens the meeting slideover from the title expand control', function (): void {
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

it('renders a compact row with a hover expand control on the title', function (): void {
    $meeting = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Call',
        'starts_at' => Date::parse('2026-09-09 16:00:00'),
        'ends_at' => Date::parse('2026-09-09 17:00:00'),
    ]);
    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->id,
        'name' => 'Maya Chen',
        'email_address' => 'maya@example.test',
    ]);

    $html = html_entity_decode(livewire(MeetingsHomeWidget::class)->html());

    expect($html)
        ->toContain('data-testid="meeting-card-title"')
        ->toContain('data-testid="meeting-card-open"')
        ->toContain('data-testid="meeting-card-row"')
        ->toContain('data-testid="meeting-card-participants"')
        ->toContain('M3.75 3.75v4.5m0-4.5h4.5')
        ->toContain('group-hover/title:opacity-100')
        ->toContain('aria-expanded="false"')
        ->toContain('x-cloak')
        ->toContain('x-collapse.duration.300ms')
        ->not->toContain('data-testid="meeting-card-participants-preview"');
});

it('shows four meetings and load more when the day has more', function (): void {
    foreach (range(1, 5) as $index) {
        Meeting::factory()->create([
            'team_id' => $this->team->id,
            'connected_account_id' => $this->account->id,
            'title' => "Meeting {$index}",
            'starts_at' => Date::parse('2026-09-09 10:00:00')->addHours($index),
            'ends_at' => Date::parse('2026-09-09 10:30:00')->addHours($index),
        ]);
    }

    livewire(MeetingsHomeWidget::class)
        ->assertSee('Meeting 1')
        ->assertSee('Meeting 4')
        ->assertDontSee('Meeting 5')
        ->assertSee(__('filament/pages/dashboard.meetings.load_more'))
        ->call('loadMore')
        ->assertSee('Meeting 5')
        ->assertDontSee(__('filament/pages/dashboard.meetings.load_more'));
});

it('does not show load more when the day has four meetings', function (): void {
    foreach (range(1, 4) as $index) {
        Meeting::factory()->create([
            'team_id' => $this->team->id,
            'connected_account_id' => $this->account->id,
            'title' => "Meeting {$index}",
            'starts_at' => Date::parse('2026-09-09 10:00:00')->addHours($index),
            'ends_at' => Date::parse('2026-09-09 10:30:00')->addHours($index),
        ]);
    }

    livewire(MeetingsHomeWidget::class)
        ->assertSee('Meeting 4')
        ->assertDontSee(__('filament/pages/dashboard.meetings.load_more'));
});

it('resets the visible list when the selected day changes', function (): void {
    foreach (range(1, 5) as $index) {
        Meeting::factory()->create([
            'team_id' => $this->team->id,
            'connected_account_id' => $this->account->id,
            'title' => "Meeting {$index}",
            'starts_at' => Date::parse('2026-09-09 10:00:00')->addHours($index),
            'ends_at' => Date::parse('2026-09-09 10:30:00')->addHours($index),
        ]);
    }

    Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Tomorrow only',
        'starts_at' => Date::parse('2026-09-10 16:00:00'),
        'ends_at' => Date::parse('2026-09-10 17:00:00'),
    ]);

    livewire(MeetingsHomeWidget::class)
        ->call('loadMore')
        ->assertSee('Meeting 5')
        ->call('nextDay')
        ->assertSee('Tomorrow only')
        ->assertDontSee('Meeting 5')
        ->call('previousDay')
        ->assertSee('Meeting 4')
        ->assertDontSee('Meeting 5')
        ->assertSee(__('filament/pages/dashboard.meetings.load_more'));
});

it('does not list a teammate meeting on home when the viewer is not invited', function (): void {
    $teammate = User::factory()->create(['current_team_id' => $this->team->id]);

    $teammateAccount = ConnectedAccount::withoutEvents(
        fn (): ConnectedAccount => ConnectedAccount::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $teammate->id,
        ])
    );

    $meeting = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $teammateAccount->id,
        'title' => 'Meeting with client',
        'starts_at' => Date::parse('2026-09-09 16:00:00'),
        'ends_at' => Date::parse('2026-09-09 17:00:00'),
    ]);

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->id,
        'name' => 'Client',
        'email_address' => 'client@example.test',
        'is_self' => false,
        'response_status' => AttendeeResponseStatus::ACCEPTED,
    ]);

    livewire(MeetingsHomeWidget::class)
        ->assertDontSee('Meeting with client');

    expect(Meeting::query()
        ->withGlobalScope('visible', VisibleMeetingScope::personal($this->user))
        ->find($meeting->getKey()))
        ->toBeNull();
});

it('names a self attendee from the meeting mailbox when viewing a teammate copy', function (): void {
    $this->user->forceFill(['name' => 'Alice Viewer'])->save();

    $bob = User::factory()->create(['name' => 'Bob Owner']);
    $bob->teams()->attach($this->team, ['role' => 'admin']);
    $bob->forceFill(['current_team_id' => $this->team->id])->save();

    $bobAccount = ConnectedAccount::withoutEvents(
        fn (): ConnectedAccount => ConnectedAccount::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $bob->id,
            'email_address' => 'bob@example.com',
        ])
    );

    $meeting = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $bobAccount->id,
        'title' => 'Shared standup',
        'starts_at' => Date::parse('2026-09-09 16:00:00'),
        'ends_at' => Date::parse('2026-09-09 17:00:00'),
    ]);

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->id,
        'name' => 'Calendar Bob',
        'email_address' => 'bob@example.com',
        'is_self' => true,
        'is_organizer' => true,
        'response_status' => AttendeeResponseStatus::ACCEPTED,
    ]);
    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->id,
        'name' => 'Alice Viewer',
        'email_address' => strtolower((string) $this->user->email),
        'is_self' => false,
        'response_status' => AttendeeResponseStatus::ACCEPTED,
    ]);
    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->id,
        'name' => 'Guest',
        'email_address' => 'guest@acme.test',
        'is_self' => false,
    ]);

    livewire(MeetingsHomeWidget::class)
        ->assertSee('Shared standup')
        ->call('openMeeting', $meeting->id)
        ->assertActionMounted('view')
        ->assertMountedActionModalSee('Bob Owner')
        ->assertMountedActionModalSee(__('filament/resources/meeting.attendees.host'));
});
