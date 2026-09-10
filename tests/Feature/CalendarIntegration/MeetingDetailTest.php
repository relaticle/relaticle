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
use Relaticle\EmailIntegration\Filament\Infolists\Entries\MeetingLinkedRecordsEntry;
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

mutates(MeetingsRelationManager::class, MeetingDetailInfolist::class, MeetingAttendeeEntry::class, MeetingLinkedRecordsEntry::class, MeetingAttendeePresenter::class, TeamMemberDirectory::class, MailboxDisplayNameDirectory::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
    Filament::setCurrentPanel(Filament::getPanel('app'));

    $this->account = ConnectedAccount::withoutEvents(
        fn () => ConnectedAccount::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
        ])
    );
});

it('removes the standalone meetings route', function (): void {
    expect(Route::has('filament.app.resources.meetings.index'))->toBeFalse();
});

it('lists meetings for the current team', function (): void {
    $mine = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
    ]);

    meetingDetailsOnRecord([$mine])
        ->assertCanSeeTableRecords([$mine]);
});

it('filters upcoming meetings', function (): void {
    $future = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'starts_at' => Date::now()->addDays(2),
        'ends_at' => Date::now()->addDays(2)->addHour(),
    ]);
    $past = Meeting::factory()->create([
        'team_id' => $this->team->id,
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
        'team_id' => $this->team->id,
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
    $starts = Date::parse('2026-09-30 00:00:00');
    $meeting = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Offsite',
        'starts_at' => $starts,
        'ends_at' => $starts->copy()->addDay(),
        'all_day' => true,
        'response_status' => AttendeeResponseStatus::TENTATIVE,
    ]);

    meetingDetailsOnRecord([$meeting])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalSee('Offsite')
        ->assertMountedActionModalSee(__('filament/resources/meeting.time.all_day'))
        ->assertMountedActionModalDontSee('→');
});

it('hides the rsvp pill when response status is null', function (): void {
    $starts = Date::parse('2026-09-30 05:30:00');
    $meeting = Meeting::factory()->create([
        'team_id' => $this->team->id,
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
        'team_id' => $this->team->id,
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
        'team_id' => $this->team->id,
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
        'team_id' => $this->team->id,
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
    $meeting = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
    ]);

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->id,
        'name' => 'Current User',
        'email_address' => 'self@example.test',
        'is_self' => true,
        'is_organizer' => false,
        'response_status' => AttendeeResponseStatus::ACCEPTED,
    ]);

    // A self attendee is named from this meeting copy's mailbox owner,
    // not the calendar copy of the name.
    meetingDetailsOnRecord([$meeting])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalSee($this->user->name)
        ->assertMountedActionModalSee('self@example.test')
        ->assertMountedActionModalSee('attendee-avatar-initials', escape: false)
        ->assertMountedActionModalDontSee('attendee-avatar-guest', escape: false)
        ->assertMountedActionModalSee(AttendeeResponseStatus::ACCEPTED->getLabel());
});

it('shows the linked person name above the attendee email', function (): void {
    $meeting = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
    ]);
    $person = People::factory()->for($this->team)->create(['name' => 'Maya Chen']);

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
        'team_id' => $this->team->id,
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
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
    ]);
    $mail = Email::factory()->create([
        'team_id' => $this->team->id,
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
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
    ]);
    $person = People::factory()->for($this->team)->create([
        'name' => 'mail2asmitnepali@gmail.com',
    ]);
    $mail = Email::factory()->create([
        'team_id' => $this->team->id,
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
    $stranger = User::factory()->withTeam()->create();
    $strangerAccount = ConnectedAccount::withoutEvents(
        fn (): ConnectedAccount => ConnectedAccount::factory()->create([
            'team_id' => $stranger->currentTeam->id,
            'user_id' => $stranger->id,
        ]),
    );
    $mail = Email::factory()->create([
        'team_id' => $stranger->currentTeam->id,
        'user_id' => $stranger->id,
        'connected_account_id' => $strangerAccount->id,
    ]);
    EmailParticipant::factory()->from()->create([
        'email_id' => $mail->id,
        'email_address' => 'guest@example.test',
        'name' => 'Secret Tenant Name',
    ]);

    $meeting = Meeting::factory()->create([
        'team_id' => $this->team->id,
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
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
    ]);

    foreach (['Rare Guest', 'Asmit Nepali', 'Asmit Nepali'] as $name) {
        $mail = Email::factory()->create([
            'team_id' => $this->team->id,
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
        'team_id' => $this->team->id,
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
        'team_id' => $this->team->id,
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
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
    ]);
    $person = People::factory()->for($this->team)->create(['name' => 'Linked Person']);
    $company = Company::factory()->for($this->team)->create(['name' => 'Linked Co']);
    $opportunity = Opportunity::factory()->for($this->team)->create(['name' => 'Linked Deal']);

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
        'team_id' => $this->team->id,
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
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
    ]);
    $person = People::factory()->for($this->team)->create();

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
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
    ]);
    $person = People::factory()->for($this->team)->create(['name' => 'Fresh Link']);

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
        'team_id' => $this->team->id,
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
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'starts_at' => Date::now()->addDays(2),
        'ends_at' => Date::now()->addDays(2)->addHour(),
    ]);
    $past = Meeting::factory()->create([
        'team_id' => $this->team->id,
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
    $company = Company::factory()->create(['team_id' => filament()->getTenant()->getKey()]);
    foreach ($meetings as $meeting) {
        $meeting->companies()->attach($company, ['link_source' => 'manual']);
    }

    return livewire(MeetingsRelationManager::class, ['ownerRecord' => $company, 'pageClass' => ViewCompany::class]);
}
