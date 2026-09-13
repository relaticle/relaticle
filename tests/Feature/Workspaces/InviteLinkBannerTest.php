<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;

it('renders the workspace-name banner on login when intended url is a join link', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['name' => 'Acme Co', 'user_id' => $owner->id]);

    session(['url.intended' => route('workspaces.join', ['token' => $workspace->invite_link_token])]);

    $this->get('/app/login')
        ->assertOk()
        ->assertSee("You've been invited to join", false)
        ->assertSee('Acme Co');
});

it('does not render the banner when the join token has expired', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['name' => 'Stale Workspace', 'user_id' => $owner->id]);
    $workspace->forceFill(['invite_link_token_expires_at' => now()->subDay()])->save();

    session(['url.intended' => route('workspaces.join', ['token' => $workspace->invite_link_token])]);

    $this->get('/app/login')
        ->assertOk()
        ->assertDontSee('Stale Workspace');
});

it('does not render the banner when the intended url is unrelated', function (): void {
    session(['url.intended' => '/dashboard']);

    $this->get('/app/login')
        ->assertOk()
        ->assertDontSee("You've been invited to join", false);
});

test('a token invitation link shows the workspace banner on login', function (): void {
    $workspace = User::factory()->withWorkspace()->create()->currentWorkspace;

    $invitation = $workspace->workspaceInvitations()->make(['email' => 'guest@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    session(['url.intended' => route('workspace-invitations.token.accept', ['token' => $raw])]);

    $this->get(Filament::getLoginUrl())
        ->assertOk()
        ->assertSee($workspace->name);
});

test('a guest is sent to login whether or not the invited email has an account', function (bool $accountExists): void {
    $workspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $email = $accountExists ? 'existing@example.test' : 'brand-new@example.test';

    if ($accountExists) {
        User::factory()->create(['email' => $email]);
    }

    $invitation = $workspace->workspaceInvitations()->make(['email' => $email, 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $this->get(route('workspace-invitations.token.accept', ['token' => $raw]))
        ->assertRedirect(Filament::getLoginUrl());
})->with([
    'no existing account' => false,
    'existing account' => true,
]);
