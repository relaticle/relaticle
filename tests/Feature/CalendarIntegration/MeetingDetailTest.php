<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\CompanyResource\RelationManagers\MeetingsRelationManager;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportTesting\Testable;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Filament\Infolists\Entries\MeetingAttendeeEntry;
use Relaticle\EmailIntegration\Filament\Infolists\Entries\MeetingHeaderEntry;
use Relaticle\EmailIntegration\Filament\Infolists\Entries\MeetingLinkedRecordsEntry;
use Relaticle\EmailIntegration\Filament\Infolists\Entries\MeetingTimeEntry;
use Relaticle\EmailIntegration\Filament\Infolists\MeetingDetailInfolist;
use Relaticle\EmailIntegration\Livewire\MeetingsHomeWidget;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;
use Relaticle\EmailIntegration\Services\MailboxDisplayNameDirectory;
use Relaticle\EmailIntegration\Services\MeetingAttendeePresenter;
use Relaticle\EmailIntegration\Services\TeamMemberDirectory;

mutates(MeetingsRelationManager::class, MeetingDetailInfolist::class, MeetingAttendeeEntry::class, MeetingHeaderEntry::class, MeetingLinkedRecordsEntry::class, MeetingTimeEntry::class, MeetingAttendeePresenter::class, TeamMemberDirectory::class, MailboxDisplayNameDirectory::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
    Filament::setCurrentPanel(Filament::getPanel('app'));

    $this->account = ConnectedAccount::withoutEvents(
        fn () => ConnectedAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
        ])
    );
});

it('removes the standalone meetings route', function (): void {
    expect(Route::has('filament.app.resources.meetings.index'))->toBeFalse();
});

it('lists meetings for the current team', function (): void {
    $mine = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
    ]);

    meetingDetailsOnRecord([$mine])
        ->assertCanSeeTableRecords([$mine]);
});

it('filters upcoming meetings', function (): void {
    $future = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
        'starts_at' => Date::now()->addDays(2),
        'ends_at' => Date::now()->addDays(2)->addHour(),
    ]);
    $past = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
        'starts_at' => Date::now()->subDays(2),
        'ends_at' => Date::now()->subDays(2)->addHour(),
    ]);

    meetingDetailsOnRecord([$future, $past])
        ->filterTable('upcoming')
        ->assertCanSeeTableRecords([$future])
        ->assertCanNotSeeTableRecords([$past]);
});

it('shows title, time range, duration, and rsvp in the view modal', function (): void {
    $starts = Date::parse('2026-09-30 05:30:00');
    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
        'title' => 'New Meeting',
        'starts_at' => $starts,
        'ends_at' => $starts->copy()->addHour(),
        'all_day' => false,
        'response_status' => AttendeeResponseStatus::ACCEPTED,
        'html_link' => 'https://meet.example.test/abc',
        'location' => 'HQ',
        'description' => '<p>Agenda</p>',
    ]);

    meetingDetailsOnRecord([$meeting])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalSee('New Meeting')
        ->assertMountedActionModalSee('Sep 30')
        ->assertMountedActionModalSee('5:30 AM')
        ->assertMountedActionModalSee('6:30 AM')
        ->assertMountedActionModalSee('(1h)')
        ->assertMountedActionModalSee(AttendeeResponseStatus::ACCEPTED->getLabel())
        ->assertMountedActionModalSee('https://meet.example.test/abc')
        ->assertMountedActionModalSee('HQ')
        ->assertMountedActionModalSee('Agenda')
        ->assertMountedActionModalSee(__('filament/resources/meeting.sections.description.heading'));
});

it('shows all-day meetings without a clock range', function (): void {
    $starts = Date::parse('2026-09-30 00:00:00', 'UTC');
    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Offsite',
        'starts_at' => $starts,
        'ends_at' => $starts,
        'all_day' => true,
        'response_status' => AttendeeResponseStatus::TENTATIVE,
    ]);

    meetingDetailsOnRecord([$meeting])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalSee('Offsite')
        ->assertMountedActionModalSee(__('filament/resources/meeting.time.all_day'))
        ->assertMountedActionModalDontSee('→');
});

it('shows the end date after a pipe when a timed meeting spans days', function (): void {
    $starts = Date::parse('2026-09-10 05:45:00');
    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Overnight sync',
        'starts_at' => $starts,
        'ends_at' => $starts->addDay(),
        'all_day' => false,
    ]);

    meetingDetailsOnRecord([$meeting])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalSee('Overnight sync')
        ->assertMountedActionModalSee('Sep 10')
        ->assertMountedActionModalSee('5:45 AM')
        ->assertMountedActionModalSee('(1d)')
        ->assertMountedActionModalSee('Sep 11')
        ->assertMountedActionModalSee('→')
        ->assertMountedActionModalDontSee('(24h)');
});

it('shows both dates for a multi-day all-day meeting', function (): void {
    $starts = Date::parse('2026-09-10 00:00:00', 'UTC');
    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Summit',
        'starts_at' => $starts,
        'ends_at' => Date::parse('2026-09-11 00:00:00', 'UTC'),
        'all_day' => true,
    ]);

    meetingDetailsOnRecord([$meeting])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalSee('Summit')
        ->assertMountedActionModalSee('Sep 10')
        ->assertMountedActionModalSee('Sep 11')
        ->assertMountedActionModalSee('→')
        ->assertMountedActionModalSee(__('filament/resources/meeting.time.all_day'))
        ->assertMountedActionModalDontSee('12:00 AM');
});

it('does not convert an all-day midnight into a clock time in Asia/Kathmandu', function (): void {
    $this->user->forceFill(['timezone' => 'Asia/Kathmandu'])->save();
    $starts = Date::parse('2026-09-10 00:00:00', 'UTC');
    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Dashain holiday',
        'starts_at' => $starts,
        'ends_at' => $starts,
        'all_day' => true,
    ]);

    meetingDetailsOnRecord([$meeting])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalSee('Dashain holiday')
        ->assertMountedActionModalSee('Sep 10')
        ->assertMountedActionModalSee(__('filament/resources/meeting.time.all_day'))
        ->assertMountedActionModalDontSee('5:45 AM')
        ->assertMountedActionModalDontSee('12:00 AM')
        ->assertMountedActionModalDontSee('→');
});

it('shows the timed meeting in the viewer timezone', function (): void {
    $this->user->update(['timezone' => 'America/New_York']);
    $starts = Date::parse('2026-09-30 05:30:00');
    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Early standup',
        'starts_at' => $starts,
        'ends_at' => $starts->addHour(),
        'all_day' => false,
    ]);

    meetingDetailsOnRecord([$meeting])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalSee('Early standup')
        ->assertMountedActionModalSee('1:30 AM')
        ->assertMountedActionModalSee('2:30 AM')
        ->assertMountedActionModalSee('(1h)')
        ->assertMountedActionModalDontSee('5:30 AM');
});

it('hides the rsvp pill when response status is null', function (): void {
    $starts = Date::parse('2026-09-30 05:30:00');
    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
        'title' => 'No RSVP Meeting',
        'starts_at' => $starts,
        'ends_at' => $starts->copy()->addHour(),
        'all_day' => false,
        'response_status' => null,
    ]);

    meetingDetailsOnRecord([$meeting])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalSee('No RSVP Meeting')
        ->assertMountedActionModalDontSee('Accepted');
});

it('hides link, location, and description when they are empty', function (): void {
    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
        'html_link' => null,
        'location' => null,
        'description' => null,
    ]);

    meetingDetailsOnRecord([$meeting])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalDontSee('https://meet.example.test/abc')
        ->assertMountedActionModalDontSee('Rooftop Terrace 7B')
        ->assertMountedActionModalDontSee('Kickoff notes')
        ->assertMountedActionModalDontSee(__('filament/resources/meeting.sections.description.heading'));
});

it('lists attendees with host and rsvp in the view modal', function (): void {
    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
    ]);

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->id,
        'name' => 'Asmit Magan',
        'email_address' => 'asmit@example.test',
        'is_organizer' => true,
        'response_status' => AttendeeResponseStatus::ACCEPTED,
    ]);
    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->id,
        'name' => 'Asmit Nepali',
        'email_address' => 'guest@example.test',
        'is_organizer' => false,
        'response_status' => AttendeeResponseStatus::NEEDS_ACTION,
    ]);

    meetingDetailsOnRecord([$meeting])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalSee(__('filament/resources/meeting.sections.participants.heading'))
        ->assertMountedActionModalSee('Asmit Magan')
        ->assertMountedActionModalSee('asmit@example.test')
        ->assertMountedActionModalSee(__('filament/resources/meeting.attendees.host'))
        ->assertMountedActionModalSee(AttendeeResponseStatus::ACCEPTED->getLabel())
        ->assertMountedActionModalSee('Asmit Nepali')
        ->assertMountedActionModalSee('guest@example.test')
        ->assertMountedActionModalSee(AttendeeResponseStatus::NEEDS_ACTION->getLabel());
});

it('shows an empty participants line when there are no attendees', function (): void {
    $starts = Date::parse('2026-09-15 11:11:00');
    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
        'starts_at' => $starts,
        'ends_at' => $starts->copy()->addMinutes(75),
        'all_day' => false,
    ]);

    meetingDetailsOnRecord([$meeting])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalSee(__('filament/resources/meeting.sections.participants.heading'))
        ->assertMountedActionModalSee('0')
        ->assertMountedActionModalSee(__('filament/resources/meeting.sections.participants.empty'));
});

it('shows the current user attendee row when is_self is true', function (): void {
    $this->user->forceFill([
        'name' => 'Oliver Workspace',
        'email' => 'oliver@relaticle.test',
    ])->save();
    $this->account->forceFill(['email_address' => 'oliver@relaticle.test'])->save();

    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
    ]);

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->id,
        'name' => 'Calendar Oliver',
        'email_address' => 'oliver@relaticle.test',
        'is_self' => true,
        'is_organizer' => false,
        'response_status' => AttendeeResponseStatus::ACCEPTED,
    ]);

    meetingDetailsOnRecord([$meeting])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalSee('Oliver Workspace')
        ->assertMountedActionModalSee('oliver@relaticle.test')
        ->assertMountedActionModalDontSee('Calendar Oliver')
        ->assertMountedActionModalSee('attendee-avatar-initials', escape: false)
        ->assertMountedActionModalDontSee('attendee-avatar-guest', escape: false)
        ->assertMountedActionModalSee(AttendeeResponseStatus::ACCEPTED->getLabel());
});

it('does not name a self attendee from the workspace user when the mailbox address differs', function (): void {
    $this->user->forceFill([
        'name' => 'Oliver Workspace',
        'email' => 'oliver@relaticle.test',
    ])->save();
    $this->account->forceFill([
        'email_address' => 'whiteshark.devs@example.test',
        'display_name' => 'Whiteshark',
    ])->save();

    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
    ]);

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->id,
        'name' => null,
        'email_address' => 'whiteshark.devs@example.test',
        'is_self' => true,
        'is_organizer' => false,
        'response_status' => AttendeeResponseStatus::NEEDS_ACTION,
    ]);

    meetingDetailsOnRecord([$meeting])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalSee('whiteshark.devs@example.test')
        ->assertMountedActionModalDontSee('Oliver Workspace')
        ->assertMountedActionModalDontSee('Whiteshark');
});

it('keeps the calendar name for a guest on a different connected mailbox', function (): void {
    $this->user->forceFill([
        'name' => 'Oliver Workspace',
        'email' => 'oliver@relaticle.test',
    ])->save();
    $this->account->forceFill([
        'email_address' => 'whiteshark.devs@example.test',
        'display_name' => 'Whiteshark',
    ])->save();

    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
    ]);

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->id,
        'name' => 'Whiteshark Devs',
        'email_address' => 'whiteshark.devs@example.test',
        'is_self' => false,
        'is_organizer' => false,
        'response_status' => AttendeeResponseStatus::NEEDS_ACTION,
    ]);

    meetingDetailsOnRecord([$meeting])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalSee('Whiteshark Devs')
        ->assertMountedActionModalSee('whiteshark.devs@example.test')
        ->assertMountedActionModalDontSee('Oliver Workspace');
});

it('shows the linked person name above the attendee email', function (): void {
    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
    ]);
    $person = People::factory()->for($this->workspace)->create(['name' => 'Maya Chen']);

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->id,
        'name' => 'maya@example.test',
        'email_address' => 'maya@example.test',
        'contact_id' => $person->id,
        'is_organizer' => false,
        'response_status' => AttendeeResponseStatus::ACCEPTED,
    ]);

    meetingDetailsOnRecord([$meeting])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalSee('Maya Chen')
        ->assertMountedActionModalSee('maya@example.test')
        ->assertMountedActionModalSee('attendee-avatar-initials', escape: false)
        ->assertMountedActionModalDontSee('attendee-avatar-guest', escape: false);
});

it('shows the address itself rather than inventing a name from the email', function (): void {
    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
    ]);

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->id,
        'name' => 'only@example.test',
        'email_address' => 'only@example.test',
        'is_organizer' => false,
        'response_status' => AttendeeResponseStatus::NEEDS_ACTION,
    ]);

    meetingDetailsOnRecord([$meeting])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalSee('only@example.test')
        ->assertMountedActionModalDontSee(__('filament/resources/meeting.attendees.guest'))
        ->assertMountedActionModalSee('attendee-avatar-guest', escape: false)
        ->assertMountedActionModalDontSee('attendee-avatar-initials', escape: false);
});

it('names a guest from mailbox history without a person record', function (): void {
    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
    ]);
    $mail = Email::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
    ]);
    EmailParticipant::factory()->from()->create([
        'email_id' => $mail->id,
        'email_address' => 'mail2asmitnepali@gmail.com',
        'name' => 'Asmit Nepali',
    ]);

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->id,
        'name' => null,
        'email_address' => 'mail2asmitnepali@gmail.com',
        'is_organizer' => false,
        'response_status' => AttendeeResponseStatus::NEEDS_ACTION,
    ]);

    meetingDetailsOnRecord([$meeting])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalSee('Asmit Nepali')
        ->assertMountedActionModalSee('mail2asmitnepali@gmail.com')
        ->assertMountedActionModalSee('attendee-avatar-initials', escape: false)
        ->assertMountedActionModalDontSee('attendee-avatar-guest', escape: false);
});

it('ignores a linked person whose name is the email and uses the mailbox name', function (): void {
    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
    ]);
    $person = People::factory()->for($this->workspace)->create([
        'name' => 'mail2asmitnepali@gmail.com',
    ]);
    $mail = Email::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
    ]);
    EmailParticipant::factory()->from()->create([
        'email_id' => $mail->id,
        'email_address' => 'mail2asmitnepali@gmail.com',
        'name' => 'Asmit Nepali',
    ]);

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->id,
        'name' => null,
        'email_address' => 'mail2asmitnepali@gmail.com',
        'contact_id' => $person->id,
        'is_organizer' => false,
        'response_status' => AttendeeResponseStatus::ACCEPTED,
    ]);

    meetingDetailsOnRecord([$meeting])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalSee('Asmit Nepali')
        ->assertMountedActionModalSee('mail2asmitnepali@gmail.com')
        ->assertMountedActionModalSee('attendee-avatar-initials', escape: false)
        ->assertMountedActionModalDontSee('attendee-avatar-guest', escape: false);
});

it('does not use another team mailbox name for a guest', function (): void {
    $stranger = User::factory()->withWorkspace()->create();
    $strangerAccount = ConnectedAccount::withoutEvents(
        fn (): ConnectedAccount => ConnectedAccount::factory()->create([
            'workspace_id' => $stranger->currentWorkspace->id,
            'user_id' => $stranger->id,
        ]),
    );
    $mail = Email::factory()->create([
        'workspace_id' => $stranger->currentWorkspace->id,
        'user_id' => $stranger->id,
        'connected_account_id' => $strangerAccount->id,
    ]);
    EmailParticipant::factory()->from()->create([
        'email_id' => $mail->id,
        'email_address' => 'guest@example.test',
        'name' => 'Secret Tenant Name',
    ]);

    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
    ]);
    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->id,
        'name' => null,
        'email_address' => 'guest@example.test',
        'is_organizer' => false,
        'response_status' => AttendeeResponseStatus::NEEDS_ACTION,
    ]);

    meetingDetailsOnRecord([$meeting])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalSee('guest@example.test')
        ->assertMountedActionModalDontSee('Secret Tenant Name');
});

it('uses the most common mailbox name for a guest', function (): void {
    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
    ]);

    foreach (['Rare Guest', 'Asmit Nepali', 'Asmit Nepali'] as $name) {
        $mail = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
            'connected_account_id' => $this->account->id,
        ]);
        EmailParticipant::factory()->from()->create([
            'email_id' => $mail->id,
            'email_address' => 'guest@example.test',
            'name' => $name,
        ]);
    }

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->id,
        'name' => null,
        'email_address' => 'guest@example.test',
        'is_organizer' => false,
        'response_status' => AttendeeResponseStatus::NEEDS_ACTION,
    ]);

    meetingDetailsOnRecord([$meeting])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalSee('Asmit Nepali')
        ->assertMountedActionModalDontSee('Rare Guest');
});

it('shows link, location, and description when they are filled', function (): void {
    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
        'html_link' => 'https://meet.example.test/abc',
        'location' => 'Rooftop Terrace 7B',
        'description' => '<p>Kickoff notes</p>',
    ]);

    meetingDetailsOnRecord([$meeting])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalSee('https://meet.example.test/abc')
        ->assertMountedActionModalSee('Rooftop Terrace 7B')
        ->assertMountedActionModalSee('Kickoff notes')
        ->assertMountedActionModalSee(__('filament/resources/meeting.sections.description.heading'));
});

it('shows an empty state when a meeting has no linked records', function (): void {
    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
    ]);

    livewire(MeetingsHomeWidget::class)
        ->call('openMeeting', $meeting->id)
        ->assertMountedActionModalSee(__('filament/resources/meeting.sections.linked_records.empty.heading'))
        ->assertMountedActionModalSee(__('filament/resources/meeting.sections.linked_records.empty.description'))
        ->assertMountedActionModalSee(__('filament/resources/meeting.actions.link_records.label'));
});

it('lists linked people, companies, and opportunities in the view modal', function (): void {
    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
    ]);
    $person = People::factory()->for($this->workspace)->create(['name' => 'Linked Person']);
    $company = Company::factory()->for($this->workspace)->create(['name' => 'Linked Co']);
    $opportunity = Opportunity::factory()->for($this->workspace)->create(['name' => 'Linked Deal']);

    $meeting->people()->attach($person, ['link_source' => 'manual']);
    $meeting->companies()->attach($company, ['link_source' => 'manual']);
    $meeting->opportunities()->attach($opportunity, ['link_source' => 'manual']);

    meetingDetailsOnRecord([$meeting])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalSee(__('filament/resources/meeting.sections.linked_records.heading'))
        ->assertMountedActionModalSee('Linked Person')
        ->assertMountedActionModalSee('Linked Co')
        ->assertMountedActionModalSee('Linked Deal');
});

it('offers linking additional records from the meeting drawer', function (): void {
    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
    ]);

    meetingDetailsOnRecord([$meeting])
        ->assertActionVisible([
            TestAction::make('view')->table($meeting),
            TestAction::make('linkRecords'),
        ]);
});

it('links a record from the meeting view modal', function (): void {
    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
    ]);
    $person = People::factory()->for($this->workspace)->create();

    meetingDetailsOnRecord([$meeting])
        ->callAction([
            TestAction::make('view')->table($meeting),
            TestAction::make('linkRecords'),
        ], [
            'target_type' => 'People',
            'target_id' => $person->getKey(),
        ])
        ->assertNotified();

    expect($meeting->fresh()?->people()->count())->toBe(1);
});

it('shows a newly linked record in the open view modal without reloading the page', function (): void {
    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
    ]);
    $person = People::factory()->for($this->workspace)->create(['name' => 'Fresh Link']);

    meetingDetailsOnRecord([$meeting])
        ->callAction([
            TestAction::make('view')->table($meeting),
            TestAction::make('linkRecords'),
        ], [
            'target_type' => 'People',
            'target_id' => $person->getKey(),
        ])
        ->assertNotified()
        ->assertMountedActionModalSee('Fresh Link');
});

it('labels the link record picker with the selected type', function (string $type, string $labelKey): void {
    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
    ]);

    meetingDetailsOnRecord([$meeting])
        ->mountAction([
            TestAction::make('view')->table($meeting),
            TestAction::make('linkRecords'),
        ])
        ->assertFormFieldExists(
            'target_type',
            fn (Select $field): bool => $field->getLabel() === __('filament/resources/meeting.fields.record_type.label'),
        )
        ->assertFormFieldExists(
            'target_id',
            fn (Select $field): bool => $field->getLabel() === __('filament/resources/meeting.fields.record.label'),
        )
        ->fillForm(['target_type' => $type])
        ->assertFormFieldExists(
            'target_id',
            fn (Select $field): bool => $field->getLabel() === __($labelKey),
        );
})->with([
    'person' => ['People', 'filament/resources/meeting.linked_record_types.people'],
    'company' => ['Company', 'filament/resources/meeting.linked_record_types.companies'],
    'opportunity' => ['Opportunity', 'filament/resources/meeting.linked_record_types.opportunities'],
]);

it('filters past meetings', function (): void {
    $future = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
        'starts_at' => Date::now()->addDays(2),
        'ends_at' => Date::now()->addDays(2)->addHour(),
    ]);
    $past = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->id,
        'starts_at' => Date::now()->subDays(2),
        'ends_at' => Date::now()->subDays(2)->addHour(),
    ]);

    meetingDetailsOnRecord([$future, $past])
        ->filterTable('past')
        ->assertCanSeeTableRecords([$past])
        ->assertCanNotSeeTableRecords([$future]);
});

/** @param array<int, Meeting> $meetings */
function meetingDetailsOnRecord(array $meetings): Testable
{
    $company = Company::factory()->create(['workspace_id' => filament()->getTenant()->getKey()]);
    foreach ($meetings as $meeting) {
        $meeting->companies()->attach($company, ['link_source' => 'manual']);
    }

    return livewire(MeetingsRelationManager::class, ['ownerRecord' => $company, 'pageClass' => ViewCompany::class]);
}
