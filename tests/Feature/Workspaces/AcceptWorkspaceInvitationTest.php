<?php

declare(strict_types=1);

use App\Actions\Jetstream\AcceptWorkspaceInvitation;
use App\Filament\Pages\Dashboard;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(WorkspaceInvitation::class, AcceptWorkspaceInvitation::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = Workspace::factory()->create();
});

function rawTokenFor(WorkspaceInvitation $invitation): string
{
    $rawToken = $invitation->issueToken();
    $invitation->save();

    return $rawToken;
}

test('valid invitation can be accepted', function () {
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $this->workspace->id,
        'email' => $this->user->email,
        'role' => 'editor',
    ]);

    $raw = rawTokenFor($invitation);
    $acceptUrl = route('workspace-invitations.token.accept', ['token' => $raw]);
    $joinUrl = route('workspace-invitations.token.join', ['token' => $raw]);

    $this->actingAs($this->user)->get($acceptUrl)->assertOk();

    expect($this->workspace->fresh()->hasUser($this->user))->toBeFalse();

    $this->actingAs($this->user)
        ->post($joinUrl)
        ->assertRedirect(Dashboard::getUrl(['tenant' => $this->workspace]));

    expect($this->workspace->fresh()->hasUser($this->user))->toBeTrue();
    expect(WorkspaceInvitation::find($invitation->id))->toBeNull();
    expect($this->user->fresh()->current_workspace_id)->toBe($this->workspace->id);
});

test('accepting an invitation lands the user in the app panel with a visible confirmation', function (): void {
    $invitation = $this->workspace->workspaceInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    $redirect = $this->actingAs($invitee)
        ->post(route('workspace-invitations.token.join', ['token' => $raw]));

    $redirect->assertRedirect(Dashboard::getUrl(['tenant' => $this->workspace]));

    expect($redirect->headers->get('Location'))->not->toBe(config('fortify.home'))
        ->and($redirect->headers->get('Location'))->not->toBe(url('/'));

    $this->get($redirect->headers->get('Location'))
        ->assertOk()
        ->assertSee(__('workspaces.accept.joined', ['workspace' => $this->workspace->name]));
});

test('expired invitation shows the expired state', function () {
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $this->workspace->id,
        'email' => $this->user->email,
    ]);

    $raw = rawTokenFor($invitation);
    $invitation->forceFill(['expires_at' => now()->subDay()])->save();

    $acceptUrl = route('workspace-invitations.token.accept', ['token' => $raw]);

    $this->actingAs($this->user)
        ->get($acceptUrl)
        ->assertOk()
        ->assertViewIs('workspaces.accept-invitation')
        ->assertViewHas('state', 'expired');

    expect($this->workspace->fresh()->hasUser($this->user))->toBeFalse();
});

test('null expires_at is treated as expired', function () {
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $this->workspace->id,
        'email' => $this->user->email,
    ]);

    $raw = rawTokenFor($invitation);
    $invitation->forceFill(['expires_at' => null])->save();

    $acceptUrl = route('workspace-invitations.token.accept', ['token' => $raw]);

    $this->actingAs($this->user)
        ->get($acceptUrl)
        ->assertOk()
        ->assertViewIs('workspaces.accept-invitation')
        ->assertViewHas('state', 'expired');
});

test('invitation with wrong email shows the wrong-account screen, not a 403', function () {
    $invitedUser = User::factory()->withPersonalWorkspace()->create(['email' => 'invited@example.com']);
    $wrongUser = User::factory()->withPersonalWorkspace()->create(['email' => 'wrong@example.com']);

    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $this->workspace->id,
        'email' => 'invited@example.com',
    ]);

    $acceptUrl = route('workspace-invitations.token.accept', ['token' => rawTokenFor($invitation)]);

    $this->actingAs($wrongUser)
        ->get($acceptUrl)
        ->assertOk()
        ->assertViewIs('workspaces.accept-invitation')
        ->assertViewHas('state', 'wrong-account');

    expect($this->workspace->fresh()->hasUser($wrongUser))->toBeFalse();
});

test('every accept-invitation exit link points into the app panel, not the marketing homepage', function (): void {
    $appUrl = url()->getAppUrl();

    $readyInvitation = $this->workspace->workspaceInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $readyInvitation->issueToken();
    $readyInvitation->save();
    $readyInvitee = User::factory()->create(['email' => 'invitee@example.test']);

    $this->actingAs($readyInvitee)
        ->get(route('workspace-invitations.token.accept', ['token' => $raw]))
        ->assertOk()
        ->assertSee($appUrl, false);

    $wrongUser = User::factory()->withPersonalWorkspace()->create(['email' => 'wrong@example.com']);
    $mismatchedInvitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $this->workspace->id,
        'email' => 'invited@example.com',
    ]);

    $this->actingAs($wrongUser)
        ->get(route('workspace-invitations.token.accept', ['token' => rawTokenFor($mismatchedInvitation)]))
        ->assertOk()
        ->assertSee($appUrl, false);

    $expiredInvitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $this->workspace->id,
        'email' => $this->user->email,
    ]);
    $expiredRaw = rawTokenFor($expiredInvitation);
    $expiredInvitation->forceFill(['expires_at' => now()->subDay()])->save();

    $this->actingAs($this->user)
        ->get(route('workspace-invitations.token.accept', ['token' => $expiredRaw]))
        ->assertOk()
        ->assertSee($appUrl, false);
});

test('an unknown token shows the expired state rather than leaking whether it exists', function () {
    WorkspaceInvitation::factory()->create([
        'workspace_id' => $this->workspace->id,
        'email' => $this->user->email,
    ]);

    $this->actingAs($this->user)
        ->get(route('workspace-invitations.token.accept', ['token' => Str::random(40)]))
        ->assertOk()
        ->assertViewIs('workspaces.accept-invitation')
        ->assertViewHas('state', 'expired');
});

test('accepting invitation deletes the invitation record', function () {
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $this->workspace->id,
        'email' => $this->user->email,
        'role' => 'admin',
    ]);

    $joinUrl = route('workspace-invitations.token.join', ['token' => rawTokenFor($invitation)]);

    $this->actingAs($this->user)->post($joinUrl);

    expect(WorkspaceInvitation::count())->toBe(0);
});

test('user with scheduled deletion cannot accept invitation', function () {
    $user = User::factory()->withPersonalWorkspace()->scheduledForDeletion()->create();

    $workspace = Workspace::factory()->create();
    /** @var WorkspaceInvitation $invitation */
    $invitation = $workspace->workspaceInvitations()->create([
        'email' => $user->email,
        'role' => 'editor',
        'expires_at' => now()->addDays(7),
    ]);

    $joinUrl = route('workspace-invitations.token.join', ['token' => rawTokenFor($invitation)]);

    $this->actingAs($user)
        ->post($joinUrl)
        ->assertForbidden();

    expect($workspace->fresh()->hasUser($user))->toBeFalse();
});

test('a workspace scheduled for deletion cannot be joined', function (): void {
    $workspace = Workspace::factory()->create();
    $workspace->forceFill(['scheduled_deletion_at' => now()->addDays(30)])->save();

    /** @var WorkspaceInvitation $invitation */
    $invitation = $workspace->workspaceInvitations()->create([
        'email' => $this->user->email,
        'role' => 'editor',
        'expires_at' => now()->addDays(7),
    ]);

    $joinUrl = route('workspace-invitations.token.join', ['token' => rawTokenFor($invitation)]);

    $this->actingAs($this->user)
        ->post($joinUrl)
        ->assertStatus(410);

    expect($workspace->fresh()->hasUser($this->user))->toBeFalse();
    expect(WorkspaceInvitation::query()->whereKey($invitation->id)->exists())->toBeTrue();
});

test('a GET on the accept link never joins the workspace', function (): void {
    $invitation = $this->workspace->workspaceInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    $this->actingAs($invitee)
        ->get(route('workspace-invitations.token.accept', ['token' => $raw]))
        ->assertOk()
        ->assertSee($this->workspace->name);

    expect($invitee->fresh()->belongsToWorkspace($this->workspace))->toBeFalse();
});

test('the token accept route never leaks the token via the Referer header', function (): void {
    $invitation = $this->workspace->workspaceInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    $this->actingAs($invitee)
        ->get(route('workspace-invitations.token.accept', ['token' => $raw]))
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer');
});

test('unauthenticated attempts against the token accept route are rate limited', function (): void {
    $token = Str::random(40);

    foreach (range(1, 10) as $ignored) {
        $this->get(route('workspace-invitations.token.accept', ['token' => $token]));
    }

    $this->get(route('workspace-invitations.token.accept', ['token' => $token]))
        ->assertStatus(429);
});

test('a POST joins the workspace', function (): void {
    $invitation = $this->workspace->workspaceInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    $this->actingAs($invitee)
        ->post(route('workspace-invitations.token.join', ['token' => $raw]))
        ->assertRedirect();

    expect($invitee->fresh()->belongsToWorkspace($this->workspace))->toBeTrue()
        ->and(WorkspaceInvitation::query()->whereKey($invitation->id)->exists())->toBeFalse();
});

test('a mismatched email gets the wrong-account screen not a 403', function (): void {
    $invitation = $this->workspace->workspaceInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $other = User::factory()->create(['email' => 'someone-else@example.test']);

    $this->actingAs($other)
        ->get(route('workspace-invitations.token.accept', ['token' => $raw]))
        ->assertOk()
        ->assertSee('invitee@example.test')
        ->assertSee('someone-else@example.test');

    expect($other->fresh()->belongsToWorkspace($this->workspace))->toBeFalse();
});

test('email matching is case insensitive', function (): void {
    $invitation = $this->workspace->workspaceInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'INVITEE@example.test']);

    $this->actingAs($invitee)
        ->post(route('workspace-invitations.token.join', ['token' => $raw]))
        ->assertRedirect();

    expect($invitee->fresh()->belongsToWorkspace($this->workspace))->toBeTrue();
});

test('an expired invitation shows the expired state', function (): void {
    $invitation = $this->workspace->workspaceInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->expires_at = now()->subDay();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    $this->actingAs($invitee)
        ->get(route('workspace-invitations.token.accept', ['token' => $raw]))
        ->assertOk()
        ->assertSee(__('workspaces.accept.expired.heading'));
});

test('a replayed accept attaches exactly one membership', function (): void {
    $invitation = $this->workspace->workspaceInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    $this->actingAs($invitee)->post(route('workspace-invitations.token.join', ['token' => $raw]));
    $this->actingAs($invitee)->post(route('workspace-invitations.token.join', ['token' => $raw]));

    expect($this->workspace->users()->where('users.id', $invitee->id)->count())->toBe(1);
});

test('an invitation revoked in flight refuses instead of reporting a join that did not happen', function (): void {
    $invitation = $this->workspace->workspaceInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    WorkspaceInvitation::query()->whereKey($invitation->id)->delete();

    expect(fn () => resolve(AcceptWorkspaceInvitation::class)->execute($invitee, $invitation))
        ->toThrow(HttpException::class);

    expect($this->workspace->users()->where('users.id', $invitee->id)->exists())->toBeFalse()
        ->and($invitee->fresh()->current_workspace_id)->not->toBe($this->workspace->id);
});

test('a revoked invitation shows the expired state rather than a false success banner', function (): void {
    $invitation = $this->workspace->workspaceInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    WorkspaceInvitation::query()->whereKey($invitation->id)->delete();

    $this->actingAs($invitee)
        ->post(route('workspace-invitations.token.join', ['token' => $raw]))
        ->assertOk()
        ->assertSee(__('workspaces.accept.expired.heading'));

    expect($this->workspace->users()->where('users.id', $invitee->id)->exists())->toBeFalse();
});

test('an expired-in-flight invitation refuses even though the caller saw it as valid', function (): void {
    $invitation = $this->workspace->workspaceInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    WorkspaceInvitation::query()->whereKey($invitation->id)->update(['expires_at' => now()->subDay()]);

    expect(fn () => resolve(AcceptWorkspaceInvitation::class)->execute($invitee, $invitation))
        ->toThrow(HttpException::class);

    expect($this->workspace->users()->where('users.id', $invitee->id)->exists())->toBeFalse();
});

test('the legacy signed-URL routes are gone', function (): void {
    $invitation = $this->workspace->workspaceInvitations()->create([
        'email' => 'legacy@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(3),
    ]);

    $invitee = User::factory()->create(['email' => 'legacy@example.test']);

    expect(Route::has('workspace-invitations.accept'))->toBeFalse()
        ->and(Route::has('workspace-invitations.join'))->toBeFalse();

    $this->actingAs($invitee)->get("/workspace-invitations/{$invitation->id}")->assertNotFound();
    $this->actingAs($invitee)->post("/workspace-invitations/{$invitation->id}")->assertNotFound();

    expect($invitee->fresh()->belongsToWorkspace($this->workspace))->toBeFalse();
});

test('a GET when the user already belongs to the workspace redirects without erroring', function (): void {
    $invitation = $this->workspace->workspaceInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);
    $this->workspace->users()->attach($invitee, ['role' => 'editor']);

    $this->actingAs($invitee)
        ->get(route('workspace-invitations.token.accept', ['token' => $raw]))
        ->assertRedirect(Dashboard::getUrl(['tenant' => $this->workspace]));

    expect(WorkspaceInvitation::query()->whereKey($invitation->id)->exists())->toBeTrue();
});

test('a POST for an already-member user cleans up the stale invitation without erroring', function (): void {
    $invitation = $this->workspace->workspaceInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);
    $this->workspace->users()->attach($invitee, ['role' => 'editor']);

    $this->actingAs($invitee)
        ->post(route('workspace-invitations.token.join', ['token' => $raw]))
        ->assertRedirect();

    expect($this->workspace->users()->where('users.id', $invitee->id)->count())->toBe(1)
        ->and(WorkspaceInvitation::query()->whereKey($invitation->id)->exists())->toBeFalse();
});

test('viewing the invitation page does not spend the allowance the join POST needs', function (): void {
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $this->workspace->id,
        'email' => $this->user->email,
        'role' => 'editor',
    ]);

    $rawToken = rawTokenFor($invitation);

    $this->actingAs($this->user);

    foreach (range(1, 10) as $ignored) {
        $this->get(route('workspace-invitations.token.accept', ['token' => $rawToken]))->assertOk();
    }

    $this->post(route('workspace-invitations.token.join', ['token' => $rawToken]))
        ->assertRedirect(Dashboard::getUrl(['tenant' => $this->workspace]));

    expect($this->user->fresh()->belongsToWorkspace($this->workspace))->toBeTrue();
});

test('an invitation carrying an unregistered role joins on the workspace default instead of dead-ending', function (): void {
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $this->workspace->id,
        'email' => $this->user->email,
        'role' => 'legacy-role-that-no-longer-exists',
    ]);

    $rawToken = rawTokenFor($invitation);

    $this->actingAs($this->user);

    $this->post(route('workspace-invitations.token.join', ['token' => $rawToken]))
        ->assertRedirect(Dashboard::getUrl(['tenant' => $this->workspace]));

    expect($this->user->fresh()->belongsToWorkspace($this->workspace))->toBeTrue()
        ->and($this->workspace->fresh()->users()->find($this->user->id)->membership->role)
        ->toBe($this->workspace->invite_link_default_role)
        ->and(WorkspaceInvitation::query()->whereKey($invitation->id)->exists())->toBeFalse();
});

test('an invitation carrying no role joins on the workspace default', function (): void {
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $this->workspace->id,
        'email' => $this->user->email,
        'role' => null,
    ]);

    $rawToken = rawTokenFor($invitation);

    $this->actingAs($this->user);

    $this->post(route('workspace-invitations.token.join', ['token' => $rawToken]))
        ->assertRedirect(Dashboard::getUrl(['tenant' => $this->workspace]));

    expect($this->workspace->fresh()->users()->find($this->user->id)->membership->role)
        ->toBe($this->workspace->invite_link_default_role);
});

test('an invitation whose stored email kept its original case still matches the invitee', function (): void {
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $this->workspace->id,
        'email' => $this->user->email,
        'role' => 'editor',
    ]);

    $rawToken = rawTokenFor($invitation);

    DB::table('workspace_invitations')
        ->where('id', $invitation->id)
        ->update(['email' => Str::upper($this->user->email)]);

    $this->actingAs($this->user);

    $this->post(route('workspace-invitations.token.join', ['token' => $rawToken]))
        ->assertRedirect(Dashboard::getUrl(['tenant' => $this->workspace]));

    expect($this->user->fresh()->belongsToWorkspace($this->workspace))->toBeTrue();
});

test('accepting re-reads the invitation under a row lock so concurrent accepts serialise', function (): void {
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $this->workspace->id,
        'email' => $this->user->email,
        'role' => 'editor',
    ]);

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    resolve(AcceptWorkspaceInvitation::class)->execute($this->user, $invitation);

    $locking = array_filter(
        $statements,
        fn (string $sql): bool => str_contains($sql, 'workspace_invitations') && str_contains(mb_strtolower($sql), 'for update'),
    );

    expect($locking)->not->toBeEmpty()
        ->and($this->user->fresh()->belongsToWorkspace($this->workspace))->toBeTrue();
});

test('switching account from the wrong-account screen returns to the invitation', function (): void {
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $this->workspace->id,
        'email' => 'invited@example.test',
        'role' => 'editor',
    ]);

    $rawToken = rawTokenFor($invitation);

    $this->actingAs($this->user)
        ->post(route('workspace-invitations.token.switch', ['token' => $rawToken]))
        ->assertRedirect(route('workspace-invitations.token.accept', ['token' => $rawToken]));

    expect(auth()->check())->toBeFalse();
});

test('the wrong-account screen offers the switch route, not a bare logout', function (): void {
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $this->workspace->id,
        'email' => 'invited@example.test',
        'role' => 'editor',
    ]);

    $rawToken = rawTokenFor($invitation);

    $this->actingAs($this->user)
        ->get(route('workspace-invitations.token.accept', ['token' => $rawToken]))
        ->assertOk()
        ->assertViewHas('state', 'wrong-account')
        ->assertSee(route('workspace-invitations.token.switch', ['token' => $rawToken]), escape: false);
});

test('after switching, the invitation link sends a guest to login and back again', function (): void {
    $invitee = User::factory()->create(['email' => 'invited@example.test']);

    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $this->workspace->id,
        'email' => $invitee->email,
        'role' => 'editor',
    ]);

    $rawToken = rawTokenFor($invitation);
    $acceptUrl = route('workspace-invitations.token.accept', ['token' => $rawToken]);

    $this->actingAs($this->user)->post(route('workspace-invitations.token.switch', ['token' => $rawToken]));

    $this->get($acceptUrl)->assertRedirect(route('filament.app.auth.login'));

    expect(session('url.intended'))->toBe($acceptUrl);

    $this->actingAs($invitee)->get($acceptUrl)->assertOk()->assertViewHas('state', 'ready');
});

test('the accept page explains what the invited role allows', function (): void {
    $invitation = $this->workspace->workspaceInvitations()->make(['email' => 'invitee@example.test', 'role' => 'viewer']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    $this->actingAs($invitee)
        ->get(route('workspace-invitations.token.accept', ['token' => $raw]))
        ->assertOk()
        ->assertSee(__('workspaces.roles.viewer.description'));
});

test('the accept page counts the people already in the workspace', function (): void {
    $invitation = $this->workspace->workspaceInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    $this->actingAs($invitee)
        ->get(route('workspace-invitations.token.accept', ['token' => $raw]))
        ->assertOk()
        ->assertSee('1 person is already in this workspace');
});
