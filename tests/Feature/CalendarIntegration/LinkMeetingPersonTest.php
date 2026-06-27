<?php

declare(strict_types=1);

use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Relaticle\EmailIntegration\Actions\LinkMeetingAction;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;

mutates(LinkMeetingAction::class);

function savePersonEmail(People $person, string $email): void
{
    $field = CustomField::query()
        ->withoutGlobalScopes()
        ->where('code', 'emails')
        ->where('entity_type', 'people')
        ->where('tenant_id', $person->team_id)
        ->first();

    if ($field) {
        $person->saveCustomFieldValue($field, [$email], $person->team);
    }
}

it('matches an existing person by email custom-field value', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    $team = $user->currentTeam;
    Filament::setTenant($team);

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'contact_creation_mode' => ContactCreationMode::None,
    ]));

    $person = People::factory()->for($team)->create();
    savePersonEmail($person, 'guest@acme.com');

    $meeting = Meeting::factory()->create([
        'team_id' => $account->team_id,
        'connected_account_id' => $account->getKey(),
    ]);
    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->getKey(),
        'email_address' => 'guest@acme.com',
        'is_self' => false,
        'response_status' => AttendeeResponseStatus::ACCEPTED,
    ]);

    (app(LinkMeetingAction::class))->execute($meeting->fresh());

    $emailField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('code', 'emails')
        ->where('entity_type', 'people')
        ->where('tenant_id', $team->id)
        ->first();

    if (! $emailField) {
        $this->markTestSkipped('No emails custom field seeded for this team.');
    }

    expect($meeting->people()->count())->toBe(1);
    expect($meeting->people()->first()?->is($person))->toBeTrue();
});

it('auto-creates a person when contact_creation_mode=All', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    $team = $user->currentTeam;
    Filament::setTenant($team);

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'contact_creation_mode' => ContactCreationMode::All,
    ]));

    $meeting = Meeting::factory()->create([
        'team_id' => $account->team_id,
        'connected_account_id' => $account->getKey(),
    ]);
    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->getKey(),
        'email_address' => 'new@acme.com',
        'is_self' => false,
    ]);

    (app(LinkMeetingAction::class))->execute($meeting->fresh());

    expect(People::query()->where('team_id', $team->id)->count())->toBe(1);
    expect($meeting->people()->count())->toBe(1);
});

it('skips person creation when contact_creation_mode=None', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    $team = $user->currentTeam;

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'contact_creation_mode' => ContactCreationMode::None,
    ]));

    $meeting = Meeting::factory()->create([
        'team_id' => $account->team_id,
        'connected_account_id' => $account->getKey(),
    ]);
    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->getKey(),
        'email_address' => 'new@acme.com',
        'is_self' => false,
    ]);

    (app(LinkMeetingAction::class))->execute($meeting->fresh());

    expect(People::query()->where('team_id', $team->id)->count())->toBe(0);
});

it('requires prior meeting with the address for Bidirectional mode', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    $team = $user->currentTeam;

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'contact_creation_mode' => ContactCreationMode::Bidirectional,
    ]));

    // First meeting — no existing prior → skipped
    $m1 = Meeting::factory()->create(['team_id' => $account->team_id, 'connected_account_id' => $account->getKey()]);
    MeetingAttendee::factory()->create([
        'meeting_id' => $m1->getKey(), 'email_address' => 'bi@acme.com', 'is_self' => false,
    ]);
    (app(LinkMeetingAction::class))->execute($m1->fresh());
    expect(People::query()->where('team_id', $team->id)->count())->toBe(0);

    // Second meeting with same address — prior exists → created
    $m2 = Meeting::factory()->create(['team_id' => $account->team_id, 'connected_account_id' => $account->getKey()]);
    MeetingAttendee::factory()->create([
        'meeting_id' => $m2->getKey(), 'email_address' => 'bi@acme.com', 'is_self' => false,
    ]);
    (app(LinkMeetingAction::class))->execute($m2->fresh());
    expect(People::query()->where('team_id', $team->id)->count())->toBe(1);
});

it('increments meeting_count metrics on matched records', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    $team = $user->currentTeam;
    Filament::setTenant($team);

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'contact_creation_mode' => ContactCreationMode::All,
        'auto_create_companies' => true,
    ]));

    $meeting = Meeting::factory()->create([
        'team_id' => $account->team_id,
        'connected_account_id' => $account->getKey(),
    ]);
    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->getKey(),
        'email_address' => 'x@acme.com',
        'is_self' => false,
    ]);

    (app(LinkMeetingAction::class))->execute($meeting->fresh());

    $person = People::query()->where('team_id', $team->id)->first();
    expect($person?->meeting_count)->toBe(1);
    expect(Carbon::parse($person?->last_meeting_at)->timestamp)->toBe($meeting->starts_at->timestamp);
});

it('does not double-count metrics when a meeting is re-linked on re-sync', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    $team = $user->currentTeam;
    Filament::setTenant($team);

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'contact_creation_mode' => ContactCreationMode::All,
        'auto_create_companies' => true,
    ]));

    $meeting = Meeting::factory()->create([
        'team_id' => $account->team_id,
        'connected_account_id' => $account->getKey(),
    ]);
    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->getKey(),
        'email_address' => 'y@acme.com',
        'is_self' => false,
    ]);

    (app(LinkMeetingAction::class))->execute($meeting->fresh());
    (app(LinkMeetingAction::class))->execute($meeting->fresh());
    (app(LinkMeetingAction::class))->execute($meeting->fresh());

    $person = People::query()->where('team_id', $team->id)->first();
    expect($person?->meeting_count)->toBe(1);
});

it('never regresses last_meeting_at when an older meeting is linked after a newer one', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    $team = $user->currentTeam;
    Filament::setTenant($team);

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'contact_creation_mode' => ContactCreationMode::All,
        'auto_create_companies' => true,
    ]));

    $recent = Meeting::factory()->create([
        'team_id' => $account->team_id,
        'connected_account_id' => $account->getKey(),
        'starts_at' => Carbon::now()->addDays(3),
        'ends_at' => Carbon::now()->addDays(3)->addHour(),
    ]);
    MeetingAttendee::factory()->create([
        'meeting_id' => $recent->getKey(),
        'email_address' => 'z@acme.com',
        'is_self' => false,
    ]);

    (app(LinkMeetingAction::class))->execute($recent->fresh());

    $older = Meeting::factory()->create([
        'team_id' => $account->team_id,
        'connected_account_id' => $account->getKey(),
        'starts_at' => Carbon::now()->subDays(30),
        'ends_at' => Carbon::now()->subDays(30)->addHour(),
    ]);
    MeetingAttendee::factory()->create([
        'meeting_id' => $older->getKey(),
        'email_address' => 'z@acme.com',
        'is_self' => false,
    ]);

    (app(LinkMeetingAction::class))->execute($older->fresh());

    $person = People::query()->where('team_id', $team->id)->first();
    expect($person?->meeting_count)->toBe(2);
    expect(Carbon::parse($person?->last_meeting_at)->timestamp)->toBe($recent->starts_at->timestamp);
});
