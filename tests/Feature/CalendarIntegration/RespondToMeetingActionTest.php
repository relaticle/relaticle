<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use App\Policies\MeetingPolicy;
use Relaticle\EmailIntegration\Actions\RespondToMeetingAction;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Enums\CalendarEventStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceInterface;
use Relaticle\EmailIntegration\Services\Exceptions\MeetingResponseFailed;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(RespondToMeetingAction::class, MeetingPolicy::class);

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

it('forbids a teammate from changing someone else\'s RSVP', function (): void {
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

it('forbids RSVP on a meeting from another team', function (): void {
    $user = User::factory()->withTeam()->create();
    $foreign = User::factory()->withTeam()->create();
    $account = respondToMeetingCalendarAccount($foreign, $foreign->currentTeam);
    $meeting = respondToMeetingInvitation($account);

    expect(fn () => app(RespondToMeetingAction::class)->execute($user, $meeting, AttendeeResponseStatus::ACCEPTED))
        ->toThrow(HttpException::class);
});
