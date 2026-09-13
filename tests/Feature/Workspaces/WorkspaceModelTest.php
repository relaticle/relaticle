<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Events\WorkspaceCreated;
use App\Events\WorkspaceDeleted;
use App\Events\WorkspaceUpdated;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

mutates(Workspace::class);

test('workspace has many people', function () {
    $workspace = Workspace::factory()->create();
    $people = People::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    expect($workspace->people->first())->toBeInstanceOf(People::class)
        ->and($workspace->people()->firstWhere('id', $people->id)?->id)->toBe($people->id);
});

test('workspace has many companies', function () {
    $workspace = Workspace::factory()->create();
    $company = Company::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    expect($workspace->companies->first())->toBeInstanceOf(Company::class)
        ->and($workspace->companies()->firstWhere('id', $company->id)?->id)->toBe($company->id);
});

test('workspace has many tasks', function () {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    $workspaceTask = $workspace->tasks()->firstWhere('id', $task->id);

    expect($workspaceTask)->toBeInstanceOf(Task::class)
        ->and($workspaceTask?->id)->toBe($task->id);
});

test('workspace has many opportunities', function () {
    $workspace = Workspace::factory()->create();
    $opportunity = Opportunity::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    $workspaceOpportunity = $workspace->opportunities()->firstWhere('id', $opportunity->id);

    expect($workspaceOpportunity)->toBeInstanceOf(Opportunity::class)
        ->and($workspaceOpportunity?->id)->toBe($opportunity->id);
});

test('workspace has many notes', function () {
    $workspace = Workspace::factory()->create();
    $note = Note::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    $workspaceNote = $workspace->notes()->firstWhere('id', $note->id);

    expect($workspaceNote)->toBeInstanceOf(Note::class)
        ->and($workspaceNote?->id)->toBe($note->id);
});

test('workspace is personal workspace', function () {
    $personalWorkspace = Workspace::factory()->create([
        'personal_workspace' => true,
    ]);

    $regularWorkspace = Workspace::factory()->create([
        'personal_workspace' => false,
    ]);

    expect($personalWorkspace->isPersonalWorkspace())->toBeTrue()
        ->and($regularWorkspace->isPersonalWorkspace())->toBeFalse();
});

test('workspace has avatar', function () {
    $workspace = Workspace::factory()->create([
        'name' => 'Test Workspace',
    ]);

    expect($workspace->getFilamentAvatarUrl())->not->toBeNull();
});

test('workspace events are dispatched', function () {
    Event::fake();

    $workspace = Workspace::factory()->create([
        'name' => 'Test Workspace',
        'slug' => 'test-workspace',
    ]);

    $workspace->update([
        'name' => 'Updated Workspace',
    ]);

    $workspace->delete();

    Event::assertDispatched(WorkspaceCreated::class);
    Event::assertDispatched(WorkspaceUpdated::class);
    Event::assertDispatched(WorkspaceDeleted::class);
});

test('slug is generated from name on creation', function () {
    Event::fake()->except(
        fn (string $event) => str_starts_with($event, 'eloquent.')
    );

    $user = User::factory()->create();

    $workspace = Workspace::query()->create([
        'name' => 'Acme Corp',
        'user_id' => $user->id,
        'personal_workspace' => true,
    ]);

    expect($workspace->slug)->toBe('acme-corp');
});

test('unique slug is generated when duplicate name exists', function () {
    Event::fake()->except(
        fn (string $event) => str_starts_with($event, 'eloquent.')
    );

    $user = User::factory()->create();

    Workspace::query()->create(['name' => 'Acme Corp', 'user_id' => $user->id, 'personal_workspace' => true]);
    $second = Workspace::query()->create(['name' => 'Acme Corp', 'user_id' => $user->id, 'personal_workspace' => false]);
    $third = Workspace::query()->create(['name' => 'Acme Corp', 'user_id' => $user->id, 'personal_workspace' => false]);

    expect($second->slug)->toBe('acme-corp-1')
        ->and($third->slug)->toBe('acme-corp-2');
});

test('special characters are handled in slug generation', function () {
    Event::fake()->except(
        fn (string $event) => str_starts_with($event, 'eloquent.')
    );

    $user = User::factory()->create();

    $workspace = Workspace::query()->create([
        'name' => 'Héllo Wörld & Friends!',
        'user_id' => $user->id,
        'personal_workspace' => true,
    ]);

    expect($workspace->slug)->toBe('hello-world-friends');
});

test('explicitly provided slug is not overwritten', function () {
    Event::fake()->except(
        fn (string $event) => str_starts_with($event, 'eloquent.')
    );

    $user = User::factory()->create();

    $workspace = Workspace::query()->create([
        'name' => 'My Workspace',
        'slug' => 'custom-slug',
        'user_id' => $user->id,
        'personal_workspace' => true,
    ]);

    expect($workspace->slug)->toBe('custom-slug');
});

test('random slug is generated when name has no alphanumeric characters', function () {
    Event::fake()->except(
        fn (string $event) => str_starts_with($event, 'eloquent.')
    );

    $user = User::factory()->create();

    $workspace = Workspace::query()->create([
        'name' => '!!!***',
        'user_id' => $user->id,
        'personal_workspace' => true,
    ]);

    expect($workspace->slug)->not->toBeEmpty()
        ->and($workspace->slug)->toHaveLength(8);
});

test('slug is stable when name is updated', function () {
    $workspace = Workspace::factory()->create([
        'name' => 'Original Name',
        'slug' => 'original-name',
    ]);

    $workspace->update(['name' => 'Updated Name']);

    expect($workspace->fresh()->slug)->toBe('original-name');
});

test('auto-generated slug from reserved name gets suffixed', function () {
    Event::fake()->except(
        fn (string $event) => str_starts_with($event, 'eloquent.')
    );

    $user = User::factory()->create();

    $workspace = Workspace::query()->create([
        'name' => 'Admin',
        'user_id' => $user->id,
        'personal_workspace' => true,
    ]);

    expect($workspace->slug)->not->toBe('admin')
        ->and($workspace->slug)->toStartWith('admin-');
});

test('issueToken stores a hash and returns the raw secret', function (): void {
    $workspace = User::factory()->withWorkspace()->create()->currentWorkspace;

    $invitation = $workspace->workspaceInvitations()->make(['email' => 'x@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    expect($raw)->toHaveLength(40)
        ->and($invitation->token)->toBe(hash('sha256', $raw))
        ->and($invitation->token)->not->toBe($raw)
        ->and($invitation->expires_at->isFuture())->toBeTrue();

    expect(WorkspaceInvitation::findByRawToken($raw)?->id)->toBe($invitation->id);
    expect(WorkspaceInvitation::findByRawToken('wrong'))->toBeNull();
});

test('workspaces default their join link to the editor role', function (): void {
    $workspace = User::factory()->withWorkspace()->create()->currentWorkspace;

    expect($workspace->invite_link_default_role)->toBe(WorkspaceRole::Editor->value);
});

test('workspace invitation belongs to its inviter', function (): void {
    $workspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $inviter = User::factory()->create();

    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'inviter_id' => $inviter->id,
    ]);

    expect($invitation->inviter_id)->toBe($inviter->id)
        ->and($invitation->inviter->is($inviter))->toBeTrue();
});

test('workspace invitation inviter is nullable', function (): void {
    $invitation = WorkspaceInvitation::factory()->create([
        'inviter_id' => null,
    ]);

    expect($invitation->inviter_id)->toBeNull()
        ->and($invitation->inviter)->toBeNull();
});

test('workspace invitation token is unique', function (): void {
    $rawToken = '';
    WorkspaceInvitation::factory()->withToken($rawToken)->create();

    expect(fn () => WorkspaceInvitation::factory()->create(['token' => hash('sha256', $rawToken)]))
        ->toThrow(QueryException::class);
});

test('reserved slugs cover all top-level route segments', function () {
    $routes = Route::getRoutes();

    $excludedPrefixes = ['livewire', 'sanctum', 'filament', 'app', '__clockwork', 'clockwork', 'laravel-login-link-login', '_boost'];

    $firstSegments = collect($routes->getRoutes())
        ->map(fn ($route) => explode('/', trim($route->uri(), '/'))[0] ?? '')
        ->filter(fn (string $segment) => $segment !== '' && ! str_starts_with($segment, '{'))
        ->reject(fn (string $segment) => in_array($segment, $excludedPrefixes, true) || str_starts_with($segment, 'livewire-'))
        ->unique()
        ->values();

    $missing = $firstSegments->reject(
        fn (string $segment) => in_array($segment, Workspace::RESERVED_SLUGS, true)
    );

    expect($missing->toArray())->toBeEmpty(
        'These route segments are missing from Workspace::RESERVED_SLUGS: '.$missing->implode(', ')
    );
});

test('a user with no current workspace falls back to their personal workspace', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $personal = $user->personalWorkspace();

    $user->forceFill(['current_workspace_id' => null])->save();

    $reloaded = User::query()->findOrFail($user->getKey());

    expect($reloaded->currentWorkspace?->getKey())->toBe($personal?->getKey())
        ->and($reloaded->fresh()->current_workspace_id)->toBe($personal?->getKey());
});

test('a user cannot switch to a workspace they do not belong to', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $own = $user->currentWorkspace;
    $foreign = Workspace::factory()->create();

    expect($user->switchWorkspace($foreign))->toBeFalse()
        ->and($user->fresh()->current_workspace_id)->toBe($own->getKey())
        ->and($user->switchWorkspace($own))->toBeTrue();
});

test('removing a member clears the workspace as their current one', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $member = User::factory()->withPersonalWorkspace()->create();
    $workspace->users()->attach($member, ['role' => WorkspaceRole::Editor->value]);
    $member->forceFill(['current_workspace_id' => $workspace->getKey()])->save();

    $workspace->removeUser($member);

    expect($member->fresh()->current_workspace_id)->toBeNull()
        ->and($workspace->fresh()->users()->whereKey($member->getKey())->exists())->toBeFalse();
});

test('purging a workspace detaches its members and clears their current workspace', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $member = User::factory()->withPersonalWorkspace()->create();
    $workspace->users()->attach($member, ['role' => WorkspaceRole::Editor->value]);
    $member->forceFill(['current_workspace_id' => $workspace->getKey()])->save();

    $workspace->purge();

    expect(Workspace::query()->whereKey($workspace->getKey())->exists())->toBeFalse()
        ->and($member->fresh()->current_workspace_id)->toBeNull()
        ->and($owner->fresh()->current_workspace_id)->toBeNull()
        ->and(DB::table('workspace_user')->where('workspace_id', $workspace->getKey())->count())->toBe(0);
});
