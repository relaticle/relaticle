<?php

declare(strict_types=1);

use App\Actions\Jetstream\AddWorkspaceMember;
use App\Actions\Jetstream\CreateWorkspace;
use App\Enums\WorkspaceRole;
use App\Filament\Pages\Dashboard;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Jetstream\Events\AddingTeamMember;

mutates(Workspace::class, AddWorkspaceMember::class);

test('creating a workspace auto-generates a 40-char invite_link_token', function (): void {
    $user = User::factory()->create();

    $workspace = resolve(CreateWorkspace::class)->create($user, [
        'name' => 'Acme',
        'slug' => 'acme',
        'onboarding_use_case' => 'other',
    ]);

    expect($workspace->invite_link_token)->toBeString()->toHaveLength(40);
});

test('tokens are unique across workspaces', function (): void {
    $user = User::factory()->create();

    $first = resolve(CreateWorkspace::class)->create($user, [
        'name' => 'Workspace A',
        'slug' => 'workspace-a',
        'onboarding_use_case' => 'other',
    ]);
    $second = resolve(CreateWorkspace::class)->create($user, [
        'name' => 'Workspace B',
        'slug' => 'workspace-b',
        'onboarding_use_case' => 'other',
    ]);

    expect($first->invite_link_token)->not->toBe($second->invite_link_token);
});

test('GET on a valid token renders the join confirmation page', function (): void {
    $owner = User::factory()->create();
    $workspace = resolve(CreateWorkspace::class)->create($owner, [
        'name' => 'Acme',
        'slug' => 'acme-confirm',
        'onboarding_use_case' => 'other',
    ]);

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->get(route('workspaces.join', ['token' => $workspace->invite_link_token]))
        ->assertOk()
        ->assertSee('Join Acme')
        ->assertSee(route('workspaces.join.confirm', ['token' => $workspace->invite_link_token]), false);

    expect($workspace->fresh()->users()->where('users.id', $joiner->id)->exists())->toBeFalse();
});

test('the join page names the role the link grants', function (): void {
    $owner = User::factory()->create();
    $workspace = resolve(CreateWorkspace::class)->create($owner, [
        'name' => 'Acme',
        'slug' => 'acme-role-shown',
        'onboarding_use_case' => 'other',
    ]);
    $workspace->update(['invite_link_default_role' => WorkspaceRole::Viewer->value]);

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->get(route('workspaces.join', ['token' => $workspace->invite_link_token]))
        ->assertOk()
        ->assertSee('Viewer');
});

test('the join page offers a way out that does not join', function (): void {
    $owner = User::factory()->create();
    $workspace = resolve(CreateWorkspace::class)->create($owner, [
        'name' => 'Acme',
        'slug' => 'acme-not-now',
        'onboarding_use_case' => 'other',
    ]);

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->get(route('workspaces.join', ['token' => $workspace->invite_link_token]))
        ->assertOk()
        ->assertSee('Not now')
        ->assertSee(url()->getAppUrl(), false);

    expect($workspace->fresh()->users()->where('users.id', $joiner->id)->exists())->toBeFalse();
});

test('the join page names the account that will join', function (): void {
    $owner = User::factory()->create();
    $workspace = resolve(CreateWorkspace::class)->create($owner, [
        'name' => 'Acme',
        'slug' => 'acme-signed-in-as',
        'onboarding_use_case' => 'other',
    ]);

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->get(route('workspaces.join', ['token' => $workspace->invite_link_token]))
        ->assertOk()
        ->assertSee($joiner->email);
});

test('the join page counts the people already in the workspace', function (): void {
    $owner = User::factory()->create();
    $workspace = resolve(CreateWorkspace::class)->create($owner, [
        'name' => 'Acme',
        'slug' => 'acme-member-count',
        'onboarding_use_case' => 'other',
    ]);

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->get(route('workspaces.join', ['token' => $workspace->invite_link_token]))
        ->assertOk()
        ->assertSee('1 person is already in this workspace');

    $workspace->users()->attach(User::factory()->create(), ['role' => WorkspaceRole::Editor->value]);

    $this->actingAs($joiner)
        ->get(route('workspaces.join', ['token' => $workspace->invite_link_token]))
        ->assertOk()
        ->assertSee('2 people are already in this workspace');
});

test('the join page explains what the granted role allows', function (): void {
    $owner = User::factory()->create();
    $workspace = resolve(CreateWorkspace::class)->create($owner, [
        'name' => 'Acme',
        'slug' => 'acme-role-description',
        'onboarding_use_case' => 'other',
    ]);
    $workspace->update(['invite_link_default_role' => WorkspaceRole::Viewer->value]);

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->get(route('workspaces.join', ['token' => $workspace->invite_link_token]))
        ->assertOk()
        ->assertSee(__('workspaces.roles.viewer.description'));
});

test('the join page does not leak its token through the Referer header', function (): void {
    $owner = User::factory()->create();
    $workspace = resolve(CreateWorkspace::class)->create($owner, [
        'name' => 'Acme',
        'slug' => 'acme-referrer',
        'onboarding_use_case' => 'other',
    ]);

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->get(route('workspaces.join', ['token' => $workspace->invite_link_token]))
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer');
});

test('POST on a valid token attaches the user and redirects', function (): void {
    $owner = User::factory()->create();
    $workspace = resolve(CreateWorkspace::class)->create($owner, [
        'name' => 'Acme',
        'slug' => 'acme-auth',
        'onboarding_use_case' => 'other',
    ]);

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->post(route('workspaces.join.confirm', ['token' => $workspace->invite_link_token]))
        ->assertRedirect(Dashboard::getUrl(['tenant' => $workspace]));

    expect($workspace->fresh()->users()->where('users.id', $joiner->id)->exists())->toBeTrue()
        ->and($joiner->fresh()->current_workspace_id)->toBe($workspace->id);
});

test('a join request racing an identical concurrent request does not duplicate the membership', function (): void {
    $owner = User::factory()->create();
    $workspace = resolve(CreateWorkspace::class)->create($owner, [
        'name' => 'Acme',
        'slug' => 'acme-race',
        'onboarding_use_case' => 'other',
    ]);

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    Event::listen(AddingTeamMember::class, function (AddingTeamMember $event): void {
        $event->team->users()->attach($event->user, ['role' => WorkspaceRole::Editor->value]);
    });

    $this->actingAs($joiner)
        ->post(route('workspaces.join.confirm', ['token' => $workspace->invite_link_token]))
        ->assertRedirect(Dashboard::getUrl(['tenant' => $workspace]));

    expect(DB::table('workspace_user')->where('workspace_id', $workspace->id)->where('user_id', $joiner->id)->count())->toBe(1)
        ->and($joiner->fresh()->current_workspace_id)->toBe($workspace->id);
});

test('invalid token returns 404 on GET', function (): void {
    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->get(route('workspaces.join', ['token' => 'nonexistenttokenvaluexxxxxxxxxxxxxxxxxxxx']))
        ->assertNotFound();
});

test('invalid token returns 404 on POST', function (): void {
    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->post(route('workspaces.join.confirm', ['token' => 'nonexistenttokenvaluexxxxxxxxxxxxxxxxxxxx']))
        ->assertNotFound();
});

test('a disabled workspace link is unreachable via the token it used to carry', function (): void {
    $owner = User::factory()->create();
    $workspace = resolve(CreateWorkspace::class)->create($owner, [
        'name' => 'Acme',
        'slug' => 'acme-null',
        'onboarding_use_case' => 'other',
    ]);
    $originalToken = $workspace->invite_link_token;

    $workspace->disableInviteLink();

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->get(route('workspaces.join', ['token' => $originalToken]))
        ->assertNotFound();

    $this->actingAs($joiner)
        ->post(route('workspaces.join.confirm', ['token' => $originalToken]))
        ->assertNotFound();
});

test('guest hitting join link is redirected to login', function (): void {
    $owner = User::factory()->create();
    $workspace = resolve(CreateWorkspace::class)->create($owner, [
        'name' => 'Acme',
        'slug' => 'acme-guest',
        'onboarding_use_case' => 'other',
    ]);

    $this->get(route('workspaces.join', ['token' => $workspace->invite_link_token]))
        ->assertRedirect(Filament::getLoginUrl());
});

test('unauthenticated attempts against the join link route are rate limited', function (): void {
    $owner = User::factory()->create();
    $workspace = resolve(CreateWorkspace::class)->create($owner, [
        'name' => 'Acme',
        'slug' => 'acme-throttle',
        'onboarding_use_case' => 'other',
    ]);

    foreach (range(1, 10) as $ignored) {
        $this->get(route('workspaces.join', ['token' => $workspace->invite_link_token]));
    }

    $this->get(route('workspaces.join', ['token' => $workspace->invite_link_token]))
        ->assertStatus(429);
});

test('user scheduled for deletion cannot view join confirmation', function (): void {
    $owner = User::factory()->create();
    $workspace = resolve(CreateWorkspace::class)->create($owner, [
        'name' => 'Acme',
        'slug' => 'acme-scheduled',
        'onboarding_use_case' => 'other',
    ]);

    $joiner = User::factory()->create([
        'email_verified_at' => now(),
        'scheduled_deletion_at' => now()->addDays(30),
    ]);

    $this->actingAs($joiner)
        ->get(route('workspaces.join', ['token' => $workspace->invite_link_token]))
        ->assertForbidden();

    expect($workspace->fresh()->users()->where('users.id', $joiner->id)->exists())->toBeFalse();
});

test('user scheduled for deletion cannot POST to join', function (): void {
    $owner = User::factory()->create();
    $workspace = resolve(CreateWorkspace::class)->create($owner, [
        'name' => 'Acme',
        'slug' => 'acme-scheduled-post',
        'onboarding_use_case' => 'other',
    ]);

    $joiner = User::factory()->create([
        'email_verified_at' => now(),
        'scheduled_deletion_at' => now()->addDays(30),
    ]);

    $this->actingAs($joiner)
        ->post(route('workspaces.join.confirm', ['token' => $workspace->invite_link_token]))
        ->assertForbidden();

    expect($workspace->fresh()->users()->where('users.id', $joiner->id)->exists())->toBeFalse();
});

test('joining a workspace scheduled for deletion is blocked', function (): void {
    $owner = User::factory()->create();
    $workspace = resolve(CreateWorkspace::class)->create($owner, [
        'name' => 'Acme',
        'slug' => 'acme-workspace-scheduled',
        'onboarding_use_case' => 'other',
    ]);
    $workspace->forceFill(['scheduled_deletion_at' => now()->addDays(30)])->save();

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->post(route('workspaces.join.confirm', ['token' => $workspace->invite_link_token]))
        ->assertStatus(410);

    expect($workspace->fresh()->users()->where('users.id', $joiner->id)->exists())->toBeFalse();
});

test('the join link grants the configured default role', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $workspace->update(['invite_link_default_role' => WorkspaceRole::Viewer->value]);

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->post(route('workspaces.join.confirm', ['token' => $workspace->invite_link_token]))
        ->assertRedirect();

    expect($joiner->fresh()->workspaceRole($workspace->fresh())->key)
        ->toBe(WorkspaceRole::Viewer->value);
});

test('workspaces without a configured default still grant editor', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($joiner)
        ->post(route('workspaces.join.confirm', ['token' => $workspace->invite_link_token]))
        ->assertRedirect();

    expect($joiner->fresh()->workspaceRole($workspace->fresh())->key)
        ->toBe(WorkspaceRole::Editor->value);
});

test('joining via the invite link consumes a pending email invitation for the same address', function (): void {
    $owner = User::factory()->create();

    $workspace = resolve(CreateWorkspace::class)->create($owner, [
        'name' => 'Acme',
        'slug' => 'acme-consume',
        'onboarding_use_case' => 'other',
    ]);

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => $joiner->email,
        'role' => WorkspaceRole::Editor->value,
    ]);

    $this->actingAs($joiner)
        ->post(route('workspaces.join.confirm', ['token' => $workspace->invite_link_token]))
        ->assertRedirect(Dashboard::getUrl(['tenant' => $workspace]));

    expect(WorkspaceInvitation::query()->whereKey($invitation->id)->exists())->toBeFalse();
});

test('joining a workspace leaves a pending invitation to a different workspace alone', function (): void {
    $owner = User::factory()->create();

    $workspace = resolve(CreateWorkspace::class)->create($owner, [
        'name' => 'Acme',
        'slug' => 'acme-other',
        'onboarding_use_case' => 'other',
    ]);

    $joiner = User::factory()->create(['email_verified_at' => now()]);

    $elsewhere = WorkspaceInvitation::factory()->create([
        'workspace_id' => Workspace::factory()->create()->id,
        'email' => $joiner->email,
        'role' => WorkspaceRole::Editor->value,
    ]);

    $this->actingAs($joiner)
        ->post(route('workspaces.join.confirm', ['token' => $workspace->invite_link_token]))
        ->assertRedirect(Dashboard::getUrl(['tenant' => $workspace]));

    expect(WorkspaceInvitation::query()->whereKey($elsewhere->id)->exists())->toBeTrue();
});
