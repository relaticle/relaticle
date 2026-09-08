<?php

declare(strict_types=1);

use App\Models\User;
use App\Policies\MeetingPolicy;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Filament\Actions\MeetingRsvpActions;
use Relaticle\EmailIntegration\Filament\Infolists\Entries\MeetingHeaderEntry;
use Relaticle\EmailIntegration\Filament\Infolists\MeetingDetailInfolist;
use Relaticle\EmailIntegration\Filament\Resources\MeetingResource\Pages\ListMeetings;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceInterface;
use Relaticle\EmailIntegration\Services\MeetingRespondentResolver;

mutates(MeetingRsvpActions::class, MeetingDetailInfolist::class, MeetingHeaderEntry::class, MeetingPolicy::class, MeetingRespondentResolver::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::withoutEvents(
        fn () => ConnectedAccount::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'email_address' => 'me@example.com',
            'capabilities' => ['email' => true, 'calendar' => true],
        ])
    );
});

function meetingRsvpInvitation(ConnectedAccount $account, array $overrides = []): Meeting
{
    $meeting = Meeting::factory()->create([
        'team_id' => $account->team_id,
        'connected_account_id' => $account->getKey(),
        'provider_event_id' => 'evt-list-1',
        'response_status' => $overrides['response_status'] ?? AttendeeResponseStatus::NEEDS_ACTION,
        'organizer_email' => 'host@example.com',
    ]);

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->getKey(),
        'email_address' => strtolower($account->email_address),
        'is_self' => $overrides['is_self'] ?? true,
        'is_organizer' => $overrides['is_organizer'] ?? false,
        'response_status' => AttendeeResponseStatus::NEEDS_ACTION,
    ]);

    return $meeting;
}

it('renders the RSVP dropdown as Pending when the mailbox has not answered', function (): void {
    $meeting = meetingRsvpInvitation($this->account);

    livewire(ListMeetings::class)
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertSee(AttendeeResponseStatus::NEEDS_ACTION->getLabel());
});

it('labels the RSVP dropdown with the current response after the mailbox answers', function (): void {
    $meeting = meetingRsvpInvitation($this->account, [
        'response_status' => AttendeeResponseStatus::ACCEPTED,
    ]);

    livewire(ListMeetings::class)
        ->mountAction(TestAction::make('view')->table($meeting))
        ->assertSee(AttendeeResponseStatus::ACCEPTED->getLabel());
});

it('shows RSVP actions on the meeting card for invitations the mailbox owner can answer', function (): void {
    $meeting = meetingRsvpInvitation($this->account);

    livewire(ListMeetings::class)
        ->assertActionVisible([
            TestAction::make('view')->table($meeting),
            TestAction::make('acceptMeeting'),
        ])
        ->assertActionVisible([
            TestAction::make('view')->table($meeting),
            TestAction::make('maybeMeeting'),
        ])
        ->assertActionVisible([
            TestAction::make('view')->table($meeting),
            TestAction::make('declineMeeting'),
        ]);
});

it('shows RSVP actions when the signed-in user is the host even without a self attendee', function (): void {
    $meeting = Meeting::factory()->create([
        'team_id' => $this->account->team_id,
        'connected_account_id' => $this->account->getKey(),
        'provider_event_id' => 'evt-host-1',
        'response_status' => AttendeeResponseStatus::ACCEPTED,
        'organizer_email' => $this->account->email_address,
    ]);

    livewire(ListMeetings::class)
        ->assertActionVisible([
            TestAction::make('view')->table($meeting),
            TestAction::make('acceptMeeting'),
        ])
        ->assertActionVisible([
            TestAction::make('view')->table($meeting),
            TestAction::make('maybeMeeting'),
        ])
        ->assertActionVisible([
            TestAction::make('view')->table($meeting),
            TestAction::make('declineMeeting'),
        ]);
});

it('shows RSVP actions when the signed-in user is the host', function (): void {
    $meeting = meetingRsvpInvitation($this->account, ['is_organizer' => true]);

    livewire(ListMeetings::class)
        ->assertActionVisible([
            TestAction::make('view')->table($meeting),
            TestAction::make('acceptMeeting'),
        ])
        ->assertActionVisible([
            TestAction::make('view')->table($meeting),
            TestAction::make('maybeMeeting'),
        ])
        ->assertActionVisible([
            TestAction::make('view')->table($meeting),
            TestAction::make('declineMeeting'),
        ]);
});

it('hides RSVP actions for a teammate who is not listed on the guest list', function (): void {
    $meeting = meetingRsvpInvitation($this->account);

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->getKey(),
        'email_address' => 'buyer@acme-buyer.test',
        'is_self' => false,
        'is_organizer' => false,
    ]);

    $teammate = User::factory()->create();
    $this->team->users()->attach($teammate, ['role' => 'admin']);
    $teammate->switchTeam($this->team);
    $this->actingAs($teammate);
    Filament::setTenant($this->team);

    livewire(ListMeetings::class)
        ->assertCanSeeTableRecords([$meeting])
        ->assertActionHidden([
            TestAction::make('view')->table($meeting),
            TestAction::make('acceptMeeting'),
        ]);
});

it('hides RSVP actions when only the workspace email is invited but no matching calendar account is connected', function (): void {
    $meeting = meetingRsvpInvitation($this->account);

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->getKey(),
        'email_address' => 'buyer@acme-buyer.test',
        'is_self' => false,
        'is_organizer' => false,
    ]);

    $teammate = User::factory()->create(['email' => 'mail2asmitnepali@gmail.com']);
    $this->team->users()->attach($teammate, ['role' => 'admin']);
    $teammate->switchTeam($this->team);

    ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $teammate->id,
        'email_address' => 'mail2asmitnepali99@gmail.com',
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->getKey(),
        'email_address' => 'mail2asmitnepali@gmail.com',
        'is_self' => false,
        'is_organizer' => false,
        'response_status' => AttendeeResponseStatus::NEEDS_ACTION,
    ]);

    $this->actingAs($teammate);
    Filament::setTenant($this->team);

    livewire(ListMeetings::class)
        ->assertCanSeeTableRecords([$meeting])
        ->assertActionHidden([
            TestAction::make('view')->table($meeting),
            TestAction::make('acceptMeeting'),
        ]);
});

it('shows RSVP actions when the workspace email is invited and the matching calendar account is connected', function (): void {
    $meeting = meetingRsvpInvitation($this->account);

    $teammate = User::factory()->create(['email' => 'mail2asmitnepali@gmail.com']);
    $this->team->users()->attach($teammate, ['role' => 'admin']);
    $teammate->switchTeam($this->team);

    ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $teammate->id,
        'email_address' => 'mail2asmitnepali@gmail.com',
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->getKey(),
        'email_address' => 'mail2asmitnepali@gmail.com',
        'is_self' => false,
        'is_organizer' => false,
        'response_status' => AttendeeResponseStatus::NEEDS_ACTION,
    ]);

    $this->actingAs($teammate);
    Filament::setTenant($this->team);

    livewire(ListMeetings::class)
        ->assertCanSeeTableRecords([$meeting])
        ->assertActionVisible([
            TestAction::make('view')->table($meeting),
            TestAction::make('acceptMeeting'),
        ]);
});

it('shows RSVP actions when only the connected mailbox email is on the guest list', function (): void {
    $meeting = meetingRsvpInvitation($this->account);

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->getKey(),
        'email_address' => 'buyer@acme-buyer.test',
        'is_self' => false,
        'is_organizer' => false,
    ]);

    $teammate = User::factory()->create(['email' => 'mail2asmitnepali@gmail.com']);
    $this->team->users()->attach($teammate, ['role' => 'admin']);
    $teammate->switchTeam($this->team);

    ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $teammate->id,
        'email_address' => 'mail2asmitnepali99@gmail.com',
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->getKey(),
        'email_address' => 'mail2asmitnepali99@gmail.com',
        'is_self' => false,
        'is_organizer' => false,
    ]);

    $this->actingAs($teammate);
    Filament::setTenant($this->team);

    livewire(ListMeetings::class)
        ->assertCanSeeTableRecords([$meeting])
        ->assertActionVisible([
            TestAction::make('view')->table($meeting),
            TestAction::make('acceptMeeting'),
        ]);
});

it('accepts an invitation from the meeting card and updates the calendar', function (): void {
    $meeting = meetingRsvpInvitation($this->account);

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('respondToEvent')
        ->once()
        ->with('evt-list-1', AttendeeResponseStatus::ACCEPTED);

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    app()->instance(CalendarServiceFactoryInterface::class, $factory);

    livewire(ListMeetings::class)
        ->callAction([
            TestAction::make('view')->table($meeting),
            TestAction::make('acceptMeeting'),
        ])
        ->assertNotified();

    expect($meeting->fresh()?->response_status)->toBe(AttendeeResponseStatus::ACCEPTED);
});
