<?php

declare(strict_types=1);

use App\Filament\Pages\CreateWorkspace;
use App\Http\Controllers\AcceptWorkspaceInvitationController;
use App\Livewire\App\Workspaces\PendingInvitationsForUser;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;

mutates(CreateWorkspace::class, PendingInvitationsForUser::class, AcceptWorkspaceInvitationController::class);

it('can create a new workspace through the browser', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    visitAs($user, '/app/new')
        ->assertSee('Create your workspace')
        ->assertDontSee('Your name')
        ->type('[id="form.name"]', 'Second Workspace')
        ->type('[id="form.slug"]', 'second-workspace')
        ->press('button:visible:has-text("Continue")')
        ->waitForText('Start with the people you already email')
        ->assertPathIs('/app/second-workspace/setup')
        ->assertDontSee('How did you hear about us?')
        ->assertScript('new Promise(resolve => { const ready = () => document.querySelector(\'[x-data^="filamentActionModals"]\')?._x_dataStack !== undefined; const poll = () => ready() ? resolve(true) : setTimeout(poll, 25); poll(); })', true)
        ->press("I'll add people and companies myself")
        ->waitForText('Help us customize your workspace')
        ->assertScript(asyncAlpineComponentsLoaded())
        ->click('[for$="onboarding_use_case-other"]')
        ->press('button:visible:has-text("Continue")')
        ->waitForText('Invite your team')
        ->press('Get started')
        ->assertPathIs('/app/second-workspace')
        ->assertSee('Workspace created');

    expect(Workspace::where('name', 'Second Workspace')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('fits every role column of the compare roles table on a phone', function (int $width): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    visitAs($user, "/app/{$workspace->slug}/workspace/members", $width, 700)
        ->press('Invite workspace members')
        ->press('Compare roles')
        ->assertPresent('[data-role-matrix]')
        ->assertScript("(() => { const scroller = document.querySelector('[data-role-matrix]').parentElement; return scroller.scrollWidth <= scroller.clientWidth; })()", true)
        ->assertNoJavaScriptErrors();
})->with([390, 320]);

it('asks a pending invitee to join or decline in a modal only those two buttons close', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    WorkspaceInvitation::factory()->create(['email' => $user->email, 'created_at' => now()->subMinute()]);
    $second = WorkspaceInvitation::factory()->create(['email' => $user->email]);

    $modal = '[data-fi-modal-id="'.PendingInvitationsForUser::MODAL_ID.'"]';
    $isOpen = "document.querySelector('{$modal}').classList.contains('fi-modal-open')";

    $page = visitAs($user, "/app/{$workspace->slug}/companies", 1440, 900)
        ->assertScript($isOpen, true)
        ->assertMissing("{$modal} .fi-modal-close-btn");

    $page->keys("{$modal} .fi-modal-window", ['Escape']);
    $page->script("document.querySelector('{$modal} .fi-modal-window-ctn').click()");

    $page->assertScript($isOpen, true)
        ->press(__('workspaces.pending_for_user.decline'))
        ->waitForText(__('workspaces.accept.ready.heading', ['workspace' => $second->workspace->name]))
        ->assertScript($isOpen, true)
        ->click("{$modal} button.fi-btn")
        ->assertPathBeginsWith("/app/{$second->workspace->slug}")
        ->assertNoJavaScriptErrors();
});

it('joins once and says so when the emailed Join button is clicked twice', function (): void {
    $this->withVite();

    $workspace = Workspace::factory()->create();
    $invitation = $workspace->workspaceInvitations()->make(['email' => 'invitee@example.test', 'role' => 'member']);
    $rawToken = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->withPersonalWorkspace()->create(['email' => 'invitee@example.test']);

    $submitsLetThrough = "(() => { const form = document.querySelector('form'); let sent = 0; form.addEventListener('submit', (event) => { if (! event.defaultPrevented) { sent++; } }); const join = form.querySelector('button[type=submit]'); join.click(); join.click(); return sent; })()";

    visitAs($invitee, "/invitations/{$rawToken}", 1440, 900)
        ->assertScript($submitsLetThrough, 1)
        ->waitForText(__('workspaces.accept.joined', ['workspace' => $workspace->name]))
        ->assertPathBeginsWith("/app/{$workspace->slug}")
        ->assertNoJavaScriptErrors();
});

it('reloads an invitation page the browser restores from its back-forward cache', function (): void {
    $this->withVite();

    $workspace = Workspace::factory()->create();
    $invitation = $workspace->workspaceInvitations()->make(['email' => 'invitee@example.test', 'role' => 'member']);
    $rawToken = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->withPersonalWorkspace()->create(['email' => 'invitee@example.test']);

    $page = visitAs($invitee, "/invitations/{$rawToken}", 1440, 900);

    $page->script("(() => { window.restoredWithoutReload = true; document.querySelector('form#join-workspace').dispatchEvent(new CustomEvent('form-processing-started', { detail: { message: '' } })); })()");

    $page->assertScript("document.querySelector('form#join-workspace button').classList.contains('fi-processing')", true);

    $page->script("window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }))");

    $page->wait(1.5)
        ->assertScript('window.restoredWithoutReload === undefined', true)
        ->assertScript("document.querySelector('form#join-workspace button').classList.contains('fi-processing')", false);
});

it('lets the switch-account button on a wrong-account invitation submit once', function (): void {
    $this->withVite();

    $workspace = Workspace::factory()->create();
    $invitation = $workspace->workspaceInvitations()->make(['email' => 'invited@example.test', 'role' => 'member']);
    $rawToken = $invitation->issueToken();
    $invitation->save();

    $signedIn = User::factory()->withPersonalWorkspace()->create(['email' => 'someone-else@example.test']);

    $submitsLetThrough = "(() => { const form = document.querySelector('form#switch-account'); let sent = 0; form.addEventListener('submit', (event) => { if (! event.defaultPrevented) { sent++; } }); const button = form.querySelector('button[type=submit]'); button.click(); button.click(); return sent; })()";

    visitAs($signedIn, "/invitations/{$rawToken}", 1440, 900)
        ->assertScript($submitsLetThrough, 1)
        ->wait(2)
        ->assertPathIs('/app/login')
        ->assertNoJavaScriptErrors();
});
