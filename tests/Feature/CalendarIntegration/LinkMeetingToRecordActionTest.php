<?php

declare(strict_types=1);

use App\Models\People;
use App\Models\Team;
use Relaticle\EmailIntegration\Actions\LinkMeetingToRecordAction;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;

mutates(LinkMeetingToRecordAction::class);

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
