<?php

declare(strict_types=1);

use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Relaticle\EmailIntegration\Actions\LinkMeetingToRecordAction;
use Relaticle\EmailIntegration\Actions\UnlinkMeetingFromRecordAction;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(UnlinkMeetingFromRecordAction::class);

it('removes a link regardless of source', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());
    $meeting = Meeting::factory()->create([
        'workspace_id' => $account->workspace_id,
        'connected_account_id' => $account->getKey(),
    ]);
    resolve(CurrentWorkspace::class)->set($meeting->workspace);
    $person = People::factory()->for($meeting->workspace)->create();
    (app(LinkMeetingToRecordAction::class))->execute(mailboxOwnerInWorkspace($account), $meeting, $person);

    (app(UnlinkMeetingFromRecordAction::class))->execute(mailboxOwnerInWorkspace($account), $meeting, $person);

    expect($meeting->people()->count())->toBe(0);
});

it('refuses to unlink a record from another workspace', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());
    $meeting = Meeting::factory()->create([
        'workspace_id' => $account->workspace_id,
        'connected_account_id' => $account->getKey(),
    ]);
    resolve(CurrentWorkspace::class)->set($meeting->workspace);
    $foreignWorkspace = Workspace::factory()->create();
    $foreignPerson = People::factory()->for($foreignWorkspace)->create();
    $meeting->people()->attach($foreignPerson->getKey(), ['link_source' => 'manual']);
    $owner = mailboxOwnerInWorkspace($account);
    $owner->workspaces()->attach($foreignWorkspace, ['role' => 'admin']);

    expect(fn () => app(UnlinkMeetingFromRecordAction::class)->execute($owner->fresh(), $meeting, $foreignPerson))
        ->toThrow(InvalidArgumentException::class);

    expect($meeting->people()->withoutGlobalScope(WorkspaceScope::class)->count())->toBe(1);
});

it('refuses a viewer who cannot update the record', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());
    $meeting = Meeting::factory()->create([
        'workspace_id' => $account->workspace_id,
        'connected_account_id' => $account->getKey(),
    ]);
    resolve(CurrentWorkspace::class)->set($meeting->workspace);
    $person = People::factory()->for($meeting->workspace)->create();
    $meeting->people()->attach($person->getKey(), ['link_source' => 'manual']);

    expect(fn () => app(UnlinkMeetingFromRecordAction::class)->execute(mailboxOwnerInWorkspace($account, 'viewer'), $meeting, $person))
        ->toThrow(HttpException::class);

    expect($meeting->people()->count())->toBe(1);
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
    $meeting->people()->attach($person->getKey(), ['link_source' => 'manual']);
    $teammate = User::factory()->create();
    $teammate->workspaces()->attach($account->workspace_id, ['role' => 'member']);
    $teammate = $teammate->fresh();

    expect($teammate->can('update', $person))->toBeTrue()
        ->and($teammate->can('view', $meeting))->toBeFalse()
        ->and(fn () => app(UnlinkMeetingFromRecordAction::class)->execute($teammate, $meeting, $person))->toThrow(HttpException::class);
});
