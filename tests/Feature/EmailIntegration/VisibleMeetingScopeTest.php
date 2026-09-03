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

function visibleMeetingsTo(User $viewer): Collection
{
    return Meeting::query()
        ->withGlobalScope('visible', new VisibleMeetingScope($viewer))
        ->get();
}

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
