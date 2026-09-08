<?php

declare(strict_types=1);

use App\Models\People;
use App\Models\Team;
use Illuminate\Support\Facades\Date;
use Relaticle\EmailIntegration\Actions\LinkMeetingAction;
use Relaticle\EmailIntegration\Actions\LinkMeetingToRecordAction;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;

mutates(LinkMeetingToRecordAction::class, LinkMeetingAction::class);

it('creates a manual link row', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());
    $meeting = Meeting::factory()->create([
        'team_id' => $account->team_id,
        'connected_account_id' => $account->getKey(),
    ]);
    $person = People::factory()->for($meeting->team)->create();

    (app(LinkMeetingToRecordAction::class))->execute($meeting, $person);

    expect($meeting->people()->count())->toBe(1);
    expect($meeting->people()->first()?->pivot->link_source)->toBe('manual');
});

it('refuses to link a record from another team', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());
    $meeting = Meeting::factory()->create([
        'team_id' => $account->team_id,
        'connected_account_id' => $account->getKey(),
    ]);
    // A person owned by a DIFFERENT team — the cross-tenant IDOR target.
    $foreignPerson = People::factory()->for(Team::factory()->create())->create();

    expect(fn () => app(LinkMeetingToRecordAction::class)->execute($meeting, $foreignPerson))
        ->toThrow(InvalidArgumentException::class);

    expect($meeting->people()->count())->toBe(0);
});

it('is idempotent', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());
    $meeting = Meeting::factory()->create([
        'team_id' => $account->team_id,
        'connected_account_id' => $account->getKey(),
    ]);
    $person = People::factory()->for($meeting->team)->create();

    (app(LinkMeetingToRecordAction::class))->execute($meeting, $person);
    (app(LinkMeetingToRecordAction::class))->execute($meeting, $person);

    expect($meeting->people()->count())->toBe(1);
});

it('increments meeting metrics on a new manual link', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'email_address' => 'owner@acmecorp.com',
    ]));
    $meeting = Meeting::factory()->create([
        'team_id' => $account->team_id,
        'connected_account_id' => $account->getKey(),
        'organizer_email' => 'guest@clientcorp.com',
    ]);
    $person = People::factory()->for($meeting->team)->create([
        'meeting_count' => 0,
        'last_meeting_at' => null,
        'last_interaction_at' => null,
    ]);

    app(LinkMeetingToRecordAction::class)->execute($meeting, $person);

    $person->refresh();

    expect($person->meeting_count)->toBe(1)
        ->and(Date::parse($person->last_meeting_at)->timestamp)->toBe($meeting->starts_at->timestamp)
        ->and(Date::parse($person->last_interaction_at)->timestamp)->toBe($meeting->starts_at->timestamp);
});

it('does not double-count metrics when the same manual link is applied twice', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'email_address' => 'owner@acmecorp.com',
    ]));
    $meeting = Meeting::factory()->create([
        'team_id' => $account->team_id,
        'connected_account_id' => $account->getKey(),
        'organizer_email' => 'guest@clientcorp.com',
    ]);
    $person = People::factory()->for($meeting->team)->create(['meeting_count' => 0]);

    $action = app(LinkMeetingToRecordAction::class);
    $action->execute($meeting, $person);
    $action->execute($meeting, $person);

    expect($person->fresh()->meeting_count)->toBe(1);
});

it('does not double-count metrics when automatic linking runs after a manual link', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'email_address' => 'owner@acmecorp.com',
    ]));
    $meeting = Meeting::factory()->create([
        'team_id' => $account->team_id,
        'connected_account_id' => $account->getKey(),
        'organizer_email' => 'guest@clientcorp.com',
    ]);
    $person = People::factory()->for($meeting->team)->create(['meeting_count' => 0]);

    app(LinkMeetingToRecordAction::class)->execute($meeting, $person);
    app(LinkMeetingAction::class)->execute($meeting->fresh());

    expect($person->fresh()->meeting_count)->toBe(1);
});
