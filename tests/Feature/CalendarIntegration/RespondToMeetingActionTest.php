<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use App\Policies\MeetingPolicy;
use Carbon\CarbonInterface;
use Relaticle\EmailIntegration\Actions\RespondToMeetingAction;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Enums\CalendarEventStatus;
use Relaticle\EmailIntegration\Exceptions\MeetingResponseFailed;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceInterface;
use Relaticle\EmailIntegration\Services\MeetingRespondentResolver;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(RespondToMeetingAction::class, MeetingPolicy::class, MeetingRespondentResolver::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function respondToMeetingInvitation(ConnectedAccount $account, array $overrides = []): Meeting
{
    $meeting = Meeting::factory()->create([
        'team_id' => $account->team_id,
        'connected_account_id' => $account->getKey(),
        'provider_event_id' => $overrides['provider_event_id'] ?? 'evt-rsvp-1',
        'response_status' => $overrides['response_status'] ?? AttendeeResponseStatus::NEEDS_ACTION,
        'status' => $overrides['status'] ?? CalendarEventStatus::CONFIRMED,
        'organizer_email' => $overrides['organizer_email'] ?? 'host@example.com',
    ]);

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->getKey(),
        'email_address' => strtolower($account->email_address),
        'is_self' => $overrides['is_self'] ?? true,
        'is_organizer' => $overrides['is_organizer'] ?? false,
        'response_status' => $overrides['attendee_response_status'] ?? AttendeeResponseStatus::NEEDS_ACTION,
    ]);

    return $meeting->load(['attendees', 'connectedAccount', 'team']);
}

function respondToMeetingCalendarAccount(User $user, Team $team): ConnectedAccount
{
    return ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $team->getKey(),
        'user_id' => $user->getKey(),
        'email_address' => 'me@example.com',
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));
}

function teammateMailboxCopy(ConnectedAccount $account, Meeting $source, string $providerEventId = 'evt-teammate-copy'): Meeting
{
    return Meeting::factory()->create([
        'team_id' => $account->team_id,
        'connected_account_id' => $account->getKey(),
        'provider_event_id' => $providerEventId,
        'provider_recurring_event_id' => $source->provider_recurring_event_id,
        'ical_uid' => $source->ical_uid,
        'starts_at' => $source->starts_at,
        'ends_at' => $source->ends_at,
        'all_day' => $source->all_day,
        'response_status' => AttendeeResponseStatus::NEEDS_ACTION,
        'status' => CalendarEventStatus::CONFIRMED,
        'organizer_email' => $source->organizer_email,
    ]);
}

it('writes the RSVP to the provider and updates the local meeting', function (): void {
    $user = User::factory()->withTeam()->create();
    $account = respondToMeetingCalendarAccount($user, $user->currentTeam);
    $meeting = respondToMeetingInvitation($account);

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('respondToEvent')
        ->once()
        ->with('evt-rsvp-1', AttendeeResponseStatus::ACCEPTED);

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->with(Mockery::on(
        fn (ConnectedAccount $passed): bool => $passed->is($account),
    ))->andReturn($service);

    app()->instance(CalendarServiceFactoryInterface::class, $factory);

    $updated = app(RespondToMeetingAction::class)->execute($user, $meeting, AttendeeResponseStatus::ACCEPTED);

    expect($updated->response_status)->toBe(AttendeeResponseStatus::ACCEPTED)
        ->and($updated->attendees->first()?->response_status)->toBe(AttendeeResponseStatus::ACCEPTED)
        ->and($updated->trashed())->toBeFalse();
});

it('keeps a declined invitation so the RSVP can be changed again', function (): void {
    $user = User::factory()->withTeam()->create();
    $account = respondToMeetingCalendarAccount($user, $user->currentTeam);
    $meeting = respondToMeetingInvitation($account, [
        'response_status' => AttendeeResponseStatus::ACCEPTED,
        'attendee_response_status' => AttendeeResponseStatus::ACCEPTED,
    ]);

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('respondToEvent')
        ->once()
        ->with('evt-rsvp-1', AttendeeResponseStatus::DECLINED);

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    app()->instance(CalendarServiceFactoryInterface::class, $factory);

    $updated = app(RespondToMeetingAction::class)->execute($user, $meeting, AttendeeResponseStatus::DECLINED);

    expect($updated->response_status)->toBe(AttendeeResponseStatus::DECLINED)
        ->and($updated->trashed())->toBeFalse();
});

it('does not update local state when the provider rejects the RSVP', function (): void {
    $user = User::factory()->withTeam()->create();
    $account = respondToMeetingCalendarAccount($user, $user->currentTeam);
    $meeting = respondToMeetingInvitation($account);

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('respondToEvent')
        ->once()
        ->andThrow(MeetingResponseFailed::fromProvider(new RuntimeException('denied')));

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    app()->instance(CalendarServiceFactoryInterface::class, $factory);

    expect(fn () => app(RespondToMeetingAction::class)->execute($user, $meeting, AttendeeResponseStatus::ACCEPTED))
        ->toThrow(MeetingResponseFailed::class);

    expect($meeting->fresh()?->response_status)->toBe(AttendeeResponseStatus::NEEDS_ACTION);
});

it('lets the mailbox owner change RSVP when they are the host', function (): void {
    $user = User::factory()->withTeam()->create();
    $account = respondToMeetingCalendarAccount($user, $user->currentTeam);
    $meeting = respondToMeetingInvitation($account, [
        'organizer_email' => $account->email_address,
        'is_organizer' => true,
        'response_status' => AttendeeResponseStatus::ACCEPTED,
        'attendee_response_status' => AttendeeResponseStatus::ACCEPTED,
    ]);

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('respondToEvent')
        ->once()
        ->with('evt-rsvp-1', AttendeeResponseStatus::TENTATIVE);

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    app()->instance(CalendarServiceFactoryInterface::class, $factory);

    $updated = app(RespondToMeetingAction::class)->execute($user, $meeting, AttendeeResponseStatus::TENTATIVE);

    expect($updated->response_status)->toBe(AttendeeResponseStatus::TENTATIVE)
        ->and($updated->attendees->first()?->response_status)->toBe(AttendeeResponseStatus::TENTATIVE)
        ->and($updated->trashed())->toBeFalse();
});

it('lets the mailbox owner change RSVP when they are the host even without an attendee row', function (): void {
    $user = User::factory()->withTeam()->create();
    $account = respondToMeetingCalendarAccount($user, $user->currentTeam);
    $meeting = Meeting::factory()->create([
        'team_id' => $account->team_id,
        'connected_account_id' => $account->getKey(),
        'provider_event_id' => 'evt-rsvp-1',
        'response_status' => AttendeeResponseStatus::ACCEPTED,
        'status' => CalendarEventStatus::CONFIRMED,
        'organizer_email' => $account->email_address,
    ]);

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('respondToEvent')
        ->once()
        ->with('evt-rsvp-1', AttendeeResponseStatus::TENTATIVE);

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    app()->instance(CalendarServiceFactoryInterface::class, $factory);

    $updated = app(RespondToMeetingAction::class)->execute($user, $meeting, AttendeeResponseStatus::TENTATIVE);

    expect($updated->response_status)->toBe(AttendeeResponseStatus::TENTATIVE)
        ->and($updated->attendees)->toHaveCount(1)
        ->and($updated->attendees->first()?->is_self)->toBeTrue()
        ->and($updated->attendees->first()?->is_organizer)->toBeTrue()
        ->and($updated->attendees->first()?->response_status)->toBe(AttendeeResponseStatus::TENTATIVE);
});

it('forbids a teammate from changing someone else\'s RSVP when they are not on the guest list', function (): void {
    $owner = User::factory()->withTeam()->create();
    $account = respondToMeetingCalendarAccount($owner, $owner->currentTeam);
    $meeting = respondToMeetingInvitation($account);

    $teammate = User::factory()->create();
    $owner->currentTeam->users()->attach($teammate, ['role' => 'admin']);
    $teammate->switchTeam($owner->currentTeam);

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldNotReceive('respondToEvent');

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldNotReceive('make');

    app()->instance(CalendarServiceFactoryInterface::class, $factory);

    expect(fn () => app(RespondToMeetingAction::class)->execute($teammate, $meeting, AttendeeResponseStatus::ACCEPTED))
        ->toThrow(HttpException::class);
});

it('lets a teammate respond when only their connected mailbox email is on the guest list', function (): void {
    $owner = User::factory()->withTeam()->create();
    $account = respondToMeetingCalendarAccount($owner, $owner->currentTeam);
    $meeting = respondToMeetingInvitation($account);

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->getKey(),
        'email_address' => 'buyer@acme-buyer.test',
        'is_self' => false,
        'is_organizer' => false,
    ]);

    $teammate = User::factory()->create(['email' => 'mail2asmitnepali@gmail.com']);
    $owner->currentTeam->users()->attach($teammate, ['role' => 'admin']);
    $teammate->switchTeam($owner->currentTeam);

    $teammateAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $owner->currentTeam->getKey(),
        'user_id' => $teammate->getKey(),
        'email_address' => 'mail2asmitnepali99@gmail.com',
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->getKey(),
        'email_address' => 'mail2asmitnepali99@gmail.com',
        'is_self' => false,
        'is_organizer' => false,
        'response_status' => AttendeeResponseStatus::NEEDS_ACTION,
    ]);

    teammateMailboxCopy($teammateAccount, $meeting);

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('respondToEvent')
        ->once()
        ->with('evt-teammate-copy', AttendeeResponseStatus::TENTATIVE);

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')
        ->once()
        ->with(Mockery::on(fn (ConnectedAccount $passed): bool => $passed->is($teammateAccount)))
        ->andReturn($service);

    app()->instance(CalendarServiceFactoryInterface::class, $factory);

    $updated = app(RespondToMeetingAction::class)->execute(
        $teammate,
        $meeting->fresh(['attendees', 'connectedAccount']),
        AttendeeResponseStatus::TENTATIVE,
    );

    expect($updated->attendees->firstWhere('email_address', 'mail2asmitnepali99@gmail.com')?->response_status)
        ->toBe(AttendeeResponseStatus::TENTATIVE);
});

it('forbids a teammate from responding when only the workspace email is invited without a matching calendar account', function (): void {
    $owner = User::factory()->withTeam()->create();
    $account = respondToMeetingCalendarAccount($owner, $owner->currentTeam);
    $meeting = respondToMeetingInvitation($account);

    $teammate = User::factory()->create(['email' => 'mail2asmitnepali@gmail.com']);
    $owner->currentTeam->users()->attach($teammate, ['role' => 'admin']);
    $teammate->switchTeam($owner->currentTeam);

    ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $owner->currentTeam->getKey(),
        'user_id' => $teammate->getKey(),
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

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldNotReceive('respondToEvent');

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldNotReceive('make');

    app()->instance(CalendarServiceFactoryInterface::class, $factory);

    expect(fn () => app(RespondToMeetingAction::class)->execute(
        $teammate,
        $meeting->fresh(['attendees', 'connectedAccount']),
        AttendeeResponseStatus::TENTATIVE,
    ))->toThrow(HttpException::class);
});

it('lets a teammate respond when their workspace email is on the guest list and the matching calendar account is connected', function (): void {
    $owner = User::factory()->withTeam()->create();
    $account = respondToMeetingCalendarAccount($owner, $owner->currentTeam);
    $meeting = respondToMeetingInvitation($account);

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->getKey(),
        'email_address' => 'buyer@acme-buyer.test',
        'is_self' => false,
        'is_organizer' => false,
    ]);

    $teammate = User::factory()->create(['email' => 'mail2asmitnepali@gmail.com']);
    $owner->currentTeam->users()->attach($teammate, ['role' => 'admin']);
    $teammate->switchTeam($owner->currentTeam);

    $teammateAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $owner->currentTeam->getKey(),
        'user_id' => $teammate->getKey(),
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

    teammateMailboxCopy($teammateAccount, $meeting);

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('respondToEvent')
        ->once()
        ->with('evt-teammate-copy', AttendeeResponseStatus::TENTATIVE);

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')
        ->once()
        ->with(Mockery::on(fn (ConnectedAccount $passed): bool => $passed->is($teammateAccount)))
        ->andReturn($service);

    app()->instance(CalendarServiceFactoryInterface::class, $factory);

    $updated = app(RespondToMeetingAction::class)->execute($teammate, $meeting->fresh(['attendees', 'connectedAccount']), AttendeeResponseStatus::TENTATIVE);

    expect($updated->response_status)->toBe(AttendeeResponseStatus::NEEDS_ACTION)
        ->and($updated->attendees->firstWhere('email_address', 'mail2asmitnepali@gmail.com')?->response_status)
        ->toBe(AttendeeResponseStatus::TENTATIVE);
});

it('uses the connected calendar account that matches the listed mailbox identity', function (): void {
    $owner = User::factory()->withTeam()->create();
    $account = respondToMeetingCalendarAccount($owner, $owner->currentTeam);
    $meeting = respondToMeetingInvitation($account);

    $teammate = User::factory()->create(['email' => 'personal@gmail.com']);
    $owner->currentTeam->users()->attach($teammate, ['role' => 'admin']);
    $teammate->switchTeam($owner->currentTeam);

    ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $owner->currentTeam->getKey(),
        'user_id' => $teammate->getKey(),
        'email_address' => 'personal@gmail.com',
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    $workAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $owner->currentTeam->getKey(),
        'user_id' => $teammate->getKey(),
        'email_address' => 'work@company.com',
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->getKey(),
        'email_address' => 'work@company.com',
        'is_self' => false,
        'is_organizer' => false,
        'response_status' => AttendeeResponseStatus::NEEDS_ACTION,
    ]);

    teammateMailboxCopy($workAccount, $meeting);

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('respondToEvent')
        ->once()
        ->with('evt-teammate-copy', AttendeeResponseStatus::ACCEPTED);

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')
        ->once()
        ->with(Mockery::on(fn (ConnectedAccount $passed): bool => $passed->is($workAccount)))
        ->andReturn($service);

    app()->instance(CalendarServiceFactoryInterface::class, $factory);

    app(RespondToMeetingAction::class)->execute(
        $teammate,
        $meeting->fresh(['attendees', 'connectedAccount']),
        AttendeeResponseStatus::ACCEPTED,
    );
});

it('writes the teammate RSVP to the matching recurring occurrence, not an earlier copy with the same iCal UID', function (): void {
    $this->travelTo('2026-09-14 12:00:00');

    $owner = User::factory()->withTeam()->create();
    $account = respondToMeetingCalendarAccount($owner, $owner->currentTeam);
    $icalUid = 'weekly-standup@google.com';
    $thisWeekStart = now()->setTime(14, 0);
    $nextWeekStart = $thisWeekStart->addWeek();

    $thisWeekMeeting = Meeting::factory()->create([
        'team_id' => $account->team_id,
        'connected_account_id' => $account->getKey(),
        'provider_event_id' => 'owner-this-week',
        'provider_recurring_event_id' => 'google-series',
        'ical_uid' => $icalUid,
        'starts_at' => $thisWeekStart,
        'ends_at' => $thisWeekStart->addMinutes(30),
        'response_status' => AttendeeResponseStatus::NEEDS_ACTION,
        'status' => CalendarEventStatus::CONFIRMED,
        'organizer_email' => 'host@example.com',
    ]);

    $nextWeekMeeting = Meeting::factory()->create([
        'team_id' => $account->team_id,
        'connected_account_id' => $account->getKey(),
        'provider_event_id' => 'owner-next-week',
        'provider_recurring_event_id' => 'google-series',
        'ical_uid' => $icalUid,
        'starts_at' => $nextWeekStart,
        'ends_at' => $nextWeekStart->addMinutes(30),
        'response_status' => AttendeeResponseStatus::NEEDS_ACTION,
        'status' => CalendarEventStatus::CONFIRMED,
        'organizer_email' => 'host@example.com',
    ]);

    $teammate = User::factory()->create(['email' => 'mail2asmitnepali@gmail.com']);
    $owner->currentTeam->users()->attach($teammate, ['role' => 'admin']);
    $teammate->switchTeam($owner->currentTeam);

    $teammateAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $owner->currentTeam->getKey(),
        'user_id' => $teammate->getKey(),
        'email_address' => 'mail2asmitnepali@gmail.com',
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    MeetingAttendee::factory()->create([
        'meeting_id' => $nextWeekMeeting->getKey(),
        'email_address' => 'mail2asmitnepali@gmail.com',
        'is_self' => false,
        'is_organizer' => false,
        'response_status' => AttendeeResponseStatus::NEEDS_ACTION,
    ]);

    teammateMailboxCopy($teammateAccount, $thisWeekMeeting, 'aaa-teammate-this-week');
    teammateMailboxCopy($teammateAccount, $nextWeekMeeting, 'zzz-teammate-next-week');

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldNotReceive('findEventIdByICalUid');
    $service->shouldReceive('respondToEvent')
        ->once()
        ->with('zzz-teammate-next-week', AttendeeResponseStatus::ACCEPTED);

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')
        ->once()
        ->with(Mockery::on(fn (ConnectedAccount $passed): bool => $passed->is($teammateAccount)))
        ->andReturn($service);

    app()->instance(CalendarServiceFactoryInterface::class, $factory);

    app(RespondToMeetingAction::class)->execute(
        $teammate,
        $nextWeekMeeting->fresh(['attendees', 'connectedAccount']),
        AttendeeResponseStatus::ACCEPTED,
    );
});

it('writes the teammate RSVP to their own mailbox event, not the source mailbox id', function (): void {
    $owner = User::factory()->withTeam()->create();
    $account = respondToMeetingCalendarAccount($owner, $owner->currentTeam);
    $meeting = respondToMeetingInvitation($account);

    $teammate = User::factory()->create(['email' => 'mail2asmitnepali@gmail.com']);
    $owner->currentTeam->users()->attach($teammate, ['role' => 'admin']);
    $teammate->switchTeam($owner->currentTeam);

    $teammateAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $owner->currentTeam->getKey(),
        'user_id' => $teammate->getKey(),
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

    teammateMailboxCopy($teammateAccount, $meeting);

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldNotReceive('findEventIdByICalUid');
    $service->shouldReceive('respondToEvent')
        ->once()
        ->with('evt-teammate-copy', AttendeeResponseStatus::TENTATIVE);

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')
        ->once()
        ->with(Mockery::on(fn (ConnectedAccount $passed): bool => $passed->is($teammateAccount)))
        ->andReturn($service);

    app()->instance(CalendarServiceFactoryInterface::class, $factory);

    app(RespondToMeetingAction::class)->execute(
        $teammate,
        $meeting->fresh(['attendees', 'connectedAccount']),
        AttendeeResponseStatus::TENTATIVE,
    );
});

it('looks up the teammate mailbox event by iCal UID when their local copy has not synced', function (): void {
    $owner = User::factory()->withTeam()->create();
    $account = respondToMeetingCalendarAccount($owner, $owner->currentTeam);
    $meeting = respondToMeetingInvitation($account);

    $teammate = User::factory()->create(['email' => 'mail2asmitnepali@gmail.com']);
    $owner->currentTeam->users()->attach($teammate, ['role' => 'admin']);
    $teammate->switchTeam($owner->currentTeam);

    $teammateAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $owner->currentTeam->getKey(),
        'user_id' => $teammate->getKey(),
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

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('findEventIdByICalUid')
        ->once()
        ->with($meeting->ical_uid, Mockery::type(CarbonInterface::class))
        ->andReturn('evt-teammate-mailbox');
    $service->shouldReceive('respondToEvent')
        ->once()
        ->with('evt-teammate-mailbox', AttendeeResponseStatus::TENTATIVE);

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')
        ->once()
        ->with(Mockery::on(fn (ConnectedAccount $passed): bool => $passed->is($teammateAccount)))
        ->andReturn($service);

    app()->instance(CalendarServiceFactoryInterface::class, $factory);

    app(RespondToMeetingAction::class)->execute(
        $teammate,
        $meeting->fresh(['attendees', 'connectedAccount']),
        AttendeeResponseStatus::TENTATIVE,
    );
});

it('does not RSVP with another mailbox event ID when the teammate copy cannot be resolved', function (): void {
    $owner = User::factory()->withTeam()->create();
    $account = respondToMeetingCalendarAccount($owner, $owner->currentTeam);
    $meeting = respondToMeetingInvitation($account);

    $teammate = User::factory()->create(['email' => 'mail2asmitnepali@gmail.com']);
    $owner->currentTeam->users()->attach($teammate, ['role' => 'admin']);
    $teammate->switchTeam($owner->currentTeam);

    ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $owner->currentTeam->getKey(),
        'user_id' => $teammate->getKey(),
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

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('findEventIdByICalUid')->once()->andReturnNull();
    $service->shouldNotReceive('respondToEvent');

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    app()->instance(CalendarServiceFactoryInterface::class, $factory);

    expect(fn () => app(RespondToMeetingAction::class)->execute(
        $teammate,
        $meeting->fresh(['attendees', 'connectedAccount']),
        AttendeeResponseStatus::TENTATIVE,
    ))->toThrow(MeetingResponseFailed::class);
});

it('forbids RSVP on a meeting from another team', function (): void {
    $user = User::factory()->withTeam()->create();
    $foreign = User::factory()->withTeam()->create();
    $account = respondToMeetingCalendarAccount($foreign, $foreign->currentTeam);
    $meeting = respondToMeetingInvitation($account);

    expect(fn () => app(RespondToMeetingAction::class)->execute($user, $meeting, AttendeeResponseStatus::ACCEPTED))
        ->toThrow(HttpException::class);
});
