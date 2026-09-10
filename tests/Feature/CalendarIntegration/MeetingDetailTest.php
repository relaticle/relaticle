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
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;
use Relaticle\EmailIntegration\Services\MeetingAttendeePresenter;
use Relaticle\EmailIntegration\Services\TeamMemberDirectory;

mutates(MeetingsRelationManager::class, MeetingDetailInfolist::class, MeetingAttendeeEntry::class, MeetingLinkedRecordsEntry::class, MeetingAttendeePresenter::class, TeamMemberDirectory::class);

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
        ->assertMountedActionModalDontSee('asmit@example.test')
        ->assertMountedActionModalSee(__('filament/resources/meeting.attendees.host'))
        ->assertMountedActionModalSee(AttendeeResponseStatus::ACCEPTED->getLabel())
        ->assertMountedActionModalSee('Asmit Nepali')
        ->assertMountedActionModalDontSee('guest@example.test')
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

    // A self attendee is named from the signed-in account, not the calendar
    // copy of the name, so the row reads as the viewer sees themselves.
    meetingDetailsOnRecord([$meeting])
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertMountedActionModalSee($this->user->name)
        ->assertMountedActionModalDontSee('self@example.test')
        ->assertMountedActionModalSee(AttendeeResponseStatus::ACCEPTED->getLabel());
});

it('prefers the linked person name over the calendar email', function (): void {
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
        ->assertMountedActionModalDontSee('maya@example.test');
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
        ->assertMountedActionModalDontSee(__('filament/resources/meeting.attendees.guest'));
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
