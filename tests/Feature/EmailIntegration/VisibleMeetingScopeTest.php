<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailBlocklist;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;
use Relaticle\EmailIntegration\Models\Scopes\VisibleMeetingScope;
use Relaticle\EmailIntegration\Models\TeamEmailBlocklist;

mutates(VisibleMeetingScope::class);

beforeEach(function (): void {
    $this->viewer = User::factory()->withTeam()->create();
    $this->team = $this->viewer->currentTeam;
    $this->actingAs($this->viewer);
    Filament::setTenant($this->team);

    $this->coworker = User::factory()->create();
    $this->coworker->teams()->attach($this->team);
    $this->coworker->forceFill(['current_team_id' => $this->team->id])->save();

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->coworker->id,
    ]));

    $this->makeCoworkerMeeting = function (array $attendees): Meeting {
        $meeting = Meeting::factory()->create([
            'team_id' => $this->team->id,
            'connected_account_id' => $this->account->getKey(),
        ]);

        foreach ($attendees as $attendeeAddress) {
            MeetingAttendee::factory()->create([
                'meeting_id' => $meeting->id,
                'email_address' => $attendeeAddress,
                'is_self' => false,
                'response_status' => AttendeeResponseStatus::ACCEPTED,
            ]);
        }

        return $meeting;
    };
});

function visibleMeetingsTo(User $viewer, bool $personalCalendarOnly = false): Collection
{
    return Meeting::query()
        ->withGlobalScope('visible', new VisibleMeetingScope($viewer, personalCalendarOnly: $personalCalendarOnly))
        ->get();
}

it('shows a coworker meeting to a workspace member listed on the guest list even when all attendees are protected', function (): void {
    $coworkerEmail = strtolower((string) $this->coworker->email);

    $internal = ($this->makeCoworkerMeeting)([$coworkerEmail, 'mail2asmitnepali99@gmail.com']);

    expect(visibleMeetingsTo($this->coworker)->modelKeys())->toContain($internal->id);
});

it('shows a coworker meeting when only the connected mailbox identity is on the guest list', function (): void {
    ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->coworker->id,
        'email_address' => 'mail2asmitnepali99@gmail.com',
    ]));

    $internal = ($this->makeCoworkerMeeting)(['mail2asmitnepali99@gmail.com']);

    expect(visibleMeetingsTo($this->coworker)->modelKeys())->toContain($internal->id);
});

it('hides a coworker meeting when all attendees are protected', function (): void {
    TeamEmailBlocklist::factory()->protected()->email('vip@contact.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->viewer->id,
    ]);

    $protected = ($this->makeCoworkerMeeting)(['vip@contact.com']);
    $normal = ($this->makeCoworkerMeeting)(['normal@contact.com']);

    $visibleIds = visibleMeetingsTo($this->viewer)->modelKeys();

    expect($visibleIds)->toContain($normal->id)
        ->not->toContain($protected->id);
});

it('hides a coworker meeting on a personal calendar when the viewer is not invited', function (): void {
    $external = ($this->makeCoworkerMeeting)(['client@contact.com']);

    expect(visibleMeetingsTo($this->viewer, personalCalendarOnly: true)->modelKeys())
        ->not->toContain($external->id);
});

it('shows a coworker meeting on workspace scope but hides it on a personal calendar', function (): void {
    $external = ($this->makeCoworkerMeeting)(['client@contact.com']);

    expect(visibleMeetingsTo($this->viewer)->modelKeys())->toContain($external->id)
        ->and(visibleMeetingsTo($this->viewer, personalCalendarOnly: true)->modelKeys())
        ->not->toContain($external->id);
});

it('shows a personal-calendar meeting from any of the viewers connected mailboxes', function (): void {
    $secondaryAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->viewer->id,
        'email_address' => 'secondary@example.test',
    ]));

    $meeting = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $secondaryAccount->getKey(),
    ]);

    expect(visibleMeetingsTo($this->viewer, personalCalendarOnly: true)->modelKeys())
        ->toContain($meeting->id);
});

it('shows a coworker meeting on a personal calendar when the viewer is on the guest list', function (): void {
    $viewerEmail = strtolower((string) $this->viewer->email);
    $invited = ($this->makeCoworkerMeeting)([$viewerEmail, 'client@contact.com']);

    expect(visibleMeetingsTo($this->viewer, personalCalendarOnly: true)->modelKeys())
        ->toContain($invited->id);
});

it('shows a coworker meeting on a personal calendar when only a connected mailbox is on the guest list', function (): void {
    ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->viewer->id,
        'email_address' => 'viewer-mailbox@example.test',
    ]));

    $invited = ($this->makeCoworkerMeeting)(['viewer-mailbox@example.test', 'client@contact.com']);

    expect(visibleMeetingsTo($this->viewer, personalCalendarOnly: true)->modelKeys())
        ->toContain($invited->id);
});

it('shows a coworker meeting when only some attendees are protected', function (): void {
    TeamEmailBlocklist::factory()->protected()->email('vip@contact.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->viewer->id,
    ]);

    $mixed = ($this->makeCoworkerMeeting)(['vip@contact.com', 'normal@contact.com']);

    expect(visibleMeetingsTo($this->viewer)->modelKeys())->toContain($mixed->id);
});

it('hides a coworker meeting when any attendee is blocked', function (): void {
    TeamEmailBlocklist::factory()->blocked()->email('blocked@contact.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->viewer->id,
    ]);

    $blocked = ($this->makeCoworkerMeeting)(['blocked@contact.com', 'normal@contact.com']);

    expect(visibleMeetingsTo($this->viewer)->modelKeys())->not->toContain($blocked->id);
});

it('still shows a protected meeting to its mailbox owner', function (): void {
    TeamEmailBlocklist::factory()->protected()->email('vip@contact.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->viewer->id,
    ]);

    $protected = ($this->makeCoworkerMeeting)(['vip@contact.com']);

    expect(visibleMeetingsTo($this->coworker)->modelKeys())->toContain($protected->id);
});

it('hides a workspace-blocked meeting from its mailbox owner', function (): void {
    TeamEmailBlocklist::factory()->blocked()->email('blocked@contact.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->viewer->id,
    ]);

    $blocked = ($this->makeCoworkerMeeting)(['blocked@contact.com', 'normal@contact.com']);

    expect(visibleMeetingsTo($this->coworker)->modelKeys())->not->toContain($blocked->id);
});

it('hides a mailbox-blocklisted meeting from its mailbox owner', function (): void {
    EmailBlocklist::factory()->email('spam@badactor.com')->create([
        'user_id' => $this->coworker->id,
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->getKey(),
    ]);

    $blocked = ($this->makeCoworkerMeeting)(['spam@badactor.com']);

    expect(visibleMeetingsTo($this->coworker)->modelKeys())->not->toContain($blocked->id);
});

it('hides a meeting when the organizer is workspace-blocked', function (): void {
    TeamEmailBlocklist::factory()->blocked()->email('blocked-organizer@contact.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->viewer->id,
    ]);

    $meeting = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->getKey(),
        'organizer_email' => 'blocked-organizer@contact.com',
    ]);

    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->id,
        'email_address' => 'normal@contact.com',
        'is_self' => false,
        'response_status' => AttendeeResponseStatus::ACCEPTED,
    ]);

    expect(visibleMeetingsTo($this->viewer)->modelKeys())->not->toContain($meeting->id)
        ->and(visibleMeetingsTo($this->coworker)->modelKeys())->not->toContain($meeting->id);
});
