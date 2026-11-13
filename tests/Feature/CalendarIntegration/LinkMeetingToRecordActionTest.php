<?php

declare(strict_types=1);

use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\Date;
use Relaticle\EmailIntegration\Actions\LinkMeetingAction;
use Relaticle\EmailIntegration\Actions\LinkMeetingToRecordAction;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(LinkMeetingToRecordAction::class, LinkMeetingAction::class);

it('creates a manual link row', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());
    $meeting = Meeting::factory()->create([
        'workspace_id' => $account->workspace_id,
        'connected_account_id' => $account->getKey(),
    ]);
    resolve(CurrentWorkspace::class)->set($meeting->workspace);
    $person = People::factory()->for($meeting->workspace)->create();

    (app(LinkMeetingToRecordAction::class))->execute(mailboxOwnerInWorkspace($account), $meeting, $person);

    expect($meeting->people()->count())->toBe(1);
    expect($meeting->people()->first()?->pivot->link_source)->toBe('manual');
});

it('refuses to link a record from another team', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());
    $meeting = Meeting::factory()->create([
        'workspace_id' => $account->workspace_id,
        'connected_account_id' => $account->getKey(),
    ]);
    resolve(CurrentWorkspace::class)->set($meeting->workspace);
    $foreignWorkspace = Workspace::factory()->create();
    $foreignPerson = People::factory()->for($foreignWorkspace)->create();
    $owner = mailboxOwnerInWorkspace($account);
    $owner->workspaces()->attach($foreignWorkspace, ['role' => 'admin']);

    expect(fn () => app(LinkMeetingToRecordAction::class)->execute($owner->fresh(), $meeting, $foreignPerson))
        ->toThrow(InvalidArgumentException::class);

    expect($meeting->people()->withoutGlobalScope(WorkspaceScope::class)->count())->toBe(0);
});

it('is idempotent', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());
    $meeting = Meeting::factory()->create([
        'workspace_id' => $account->workspace_id,
        'connected_account_id' => $account->getKey(),
    ]);
    resolve(CurrentWorkspace::class)->set($meeting->workspace);
    $person = People::factory()->for($meeting->workspace)->create();

    (app(LinkMeetingToRecordAction::class))->execute(mailboxOwnerInWorkspace($account), $meeting, $person);
    (app(LinkMeetingToRecordAction::class))->execute(mailboxOwnerInWorkspace($account), $meeting, $person);

    expect($meeting->people()->count())->toBe(1);
});

it('increments meeting metrics on a new manual link', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'email_address' => 'owner@acmecorp.com',
    ]));
    $meeting = Meeting::factory()->create([
        'workspace_id' => $account->workspace_id,
        'connected_account_id' => $account->getKey(),
        'organizer_email' => 'guest@clientcorp.com',
    ]);
    resolve(CurrentWorkspace::class)->set($meeting->workspace);
    $person = People::factory()->for($meeting->workspace)->create([
        'meeting_count' => 0,
        'last_meeting_at' => null,
        'last_interaction_at' => null,
    ]);

    app(LinkMeetingToRecordAction::class)->execute(mailboxOwnerInWorkspace($account), $meeting, $person);

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
        'workspace_id' => $account->workspace_id,
        'connected_account_id' => $account->getKey(),
        'organizer_email' => 'guest@clientcorp.com',
    ]);
    resolve(CurrentWorkspace::class)->set($meeting->workspace);
    $person = People::factory()->for($meeting->workspace)->create(['meeting_count' => 0]);

    $action = app(LinkMeetingToRecordAction::class);
    $action->execute(mailboxOwnerInWorkspace($account), $meeting, $person);
    $action->execute(mailboxOwnerInWorkspace($account), $meeting, $person);

    expect($person->fresh()->meeting_count)->toBe(1);
});

it('does not double-count metrics when automatic linking runs after a manual link', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'email_address' => 'owner@acmecorp.com',
    ]));
    $meeting = Meeting::factory()->create([
        'workspace_id' => $account->workspace_id,
        'connected_account_id' => $account->getKey(),
        'organizer_email' => 'guest@clientcorp.com',
    ]);
    resolve(CurrentWorkspace::class)->set($meeting->workspace);
    $person = People::factory()->for($meeting->workspace)->create(['meeting_count' => 0]);

    app(LinkMeetingToRecordAction::class)->execute(mailboxOwnerInWorkspace($account), $meeting, $person);
    app(LinkMeetingAction::class)->execute($meeting->fresh());

    expect($person->fresh()->meeting_count)->toBe(1);
});

it('refuses a viewer who cannot update the record', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());
    $meeting = Meeting::factory()->create([
        'workspace_id' => $account->workspace_id,
        'connected_account_id' => $account->getKey(),
    ]);
    resolve(CurrentWorkspace::class)->set($meeting->workspace);
    $person = People::factory()->for($meeting->workspace)->create();

    expect(fn () => app(LinkMeetingToRecordAction::class)->execute(mailboxOwnerInWorkspace($account, 'viewer'), $meeting, $person))
        ->toThrow(HttpException::class);

    expect($meeting->people()->count())->toBe(0);
});

it('refuses a teammate who cannot see the meeting', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());
    mailboxOwnerInWorkspace($account);
    $meeting = Meeting::factory()->create([
        'workspace_id' => $account->workspace_id,
        'connected_account_id' => $account->getKey(),
    ]);
    resolve(CurrentWorkspace::class)->set($meeting->workspace);
    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->getKey(),
        'email_address' => $account->email_address,
        'is_self' => true,
    ]);
    $person = People::factory()->for($meeting->workspace)->create();
    $teammate = User::factory()->create();
    $teammate->workspaces()->attach($account->workspace_id, ['role' => 'member']);
    $teammate = $teammate->fresh();

    expect($teammate->can('update', $person))->toBeTrue()
        ->and($teammate->can('view', $meeting))->toBeFalse()
        ->and(fn () => app(LinkMeetingToRecordAction::class)->execute($teammate, $meeting, $person))->toThrow(HttpException::class);
});
