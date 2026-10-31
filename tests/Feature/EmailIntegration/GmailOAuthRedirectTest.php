<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use App\Models\Workspace;
use Filament\Notifications\Notification;
use Relaticle\EmailIntegration\Controllers\RedirectController;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Support\MailboxOAuthWorkspace;

mutates(RedirectController::class);

beforeEach(function (): void {
    config()->set('services.gmail.client_id', 'gmail-client-id');
    config()->set('services.gmail.client_secret', 'gmail-client-secret');
    config()->set('services.gmail.redirect', 'http://localhost/email-accounts/callback/gmail');
});

it('redirects to Google using the Gmail OAuth client and the email-account callback', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);

    $location = (string) $this->get(MailboxOAuthWorkspace::redirectUrl('gmail', $user->currentWorkspace))
        ->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toContain('accounts.google.com')
        ->and($query['client_id'] ?? null)->toBe('gmail-client-id')
        // The connect flow must return to the email-account callback, NOT the social-login one.
        ->and($query['redirect_uri'] ?? null)->toBe('http://localhost/email-accounts/callback/gmail')
        ->and(explode(' ', urldecode((string) ($query['scope'] ?? ''))))->toEqualCanonicalizing([
            'openid',
            'profile',
            'email',
            'https://www.googleapis.com/auth/gmail.readonly',
            'https://www.googleapis.com/auth/gmail.send',
            'https://www.googleapis.com/auth/calendar.events',
        ])
        ->and($query['access_type'] ?? null)->toBe('offline')
        ->and($query['prompt'] ?? null)->toBe('consent');

    expect(session(RedirectController::WORKSPACE_SESSION_KEY))->toBe($user->currentWorkspace->getKey());
});

it('does not start Google consent when the user has no workspace', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('email-accounts.redirect', ['provider' => 'gmail']))
        ->assertForbidden();

    expect(session()->has(RedirectController::WORKSPACE_SESSION_KEY))->toBeFalse();
});

it('rejects mailbox oauth redirect urls without a valid signature', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);

    $this->get(route('email-accounts.redirect', [
        'provider' => 'gmail',
        'workspace' => $user->currentWorkspace->getKey(),
    ]))->assertForbidden();
});

it('binds oauth to the page workspace when another tab switched the active team', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $pageWorkspace = $user->currentWorkspace;
    $otherWorkspace = Workspace::factory()->create(['user_id' => $user->getKey()]);
    $user->workspaces()->attach($otherWorkspace, ['role' => 'admin']);
    $user->forceFill(['current_workspace_id' => $otherWorkspace->getKey()])->save();
    $user->unsetRelation('currentWorkspace');

    $this->actingAs($user);

    $this->get(MailboxOAuthWorkspace::redirectUrl('gmail', $pageWorkspace))
        ->assertRedirect();

    expect(session(RedirectController::WORKSPACE_SESSION_KEY))->toBe($pageWorkspace->getKey());
});

it('remembers the page that started the connect so the callback can return there', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);

    $this->get(MailboxOAuthWorkspace::redirectUrl('gmail', $user->currentWorkspace, 'https://relaticle.test/app/acme/email'))
        ->assertRedirect();

    expect(session(RedirectController::RETURN_URL_SESSION_KEY))->toBe('https://relaticle.test/app/acme/email');
});

it('forgets an earlier return page when a connect starts without one', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);
    session()->put(RedirectController::RETURN_URL_SESSION_KEY, 'https://relaticle.test/app/acme/email');

    $this->get(MailboxOAuthWorkspace::redirectUrl('gmail', $user->currentWorkspace))
        ->assertRedirect();

    expect(session()->has(RedirectController::RETURN_URL_SESSION_KEY))->toBeFalse();
});

it('rejects a return page that was not signed with the connect url', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);

    $this->get(MailboxOAuthWorkspace::redirectUrl('gmail', $user->currentWorkspace).'&return=https://evil.test')
        ->assertForbidden();
});

it('refuses to start a mailbox connect for a workspace whose hosted access is paused', function (): void {
    withHostedBilling();
    $user = User::factory()->withWorkspace()->create();
    $user->currentWorkspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->subMinute()])->save();
    $this->actingAs($user);

    $response = $this->get(MailboxOAuthWorkspace::redirectUrl('gmail', $user->currentWorkspace));

    expect((string) $response->headers->get('Location'))->not->toContain('accounts.google.com')
        ->and(session(RedirectController::WORKSPACE_SESSION_KEY))->toBeNull();
});

it('does not start consent when the workspace has reached its mailbox limit', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();
    ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->count(10)->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
    ]));
    $this->actingAs($user);

    $this->get(MailboxOAuthWorkspace::redirectUrl('gmail', $workspace))
        ->assertRedirect(EmailAccountsPage::getUrl(['tenant' => $workspace->slug], panel: 'app'));

    Notification::assertNotified(
        Notification::make()->title('The Pro plan allows 10 connected mailboxes in a workspace. Upgrade to connect more.')->danger(),
    );

    expect(session()->has(RedirectController::WORKSPACE_SESSION_KEY))->toBeFalse();
});

it('starts consent at the same mailbox count on a plan with a higher limit', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Scale])->save();
    ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->count(10)->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
    ]));
    $this->actingAs($user);

    $location = (string) $this->get(MailboxOAuthWorkspace::redirectUrl('gmail', $workspace))
        ->headers->get('Location');

    expect($location)->toContain('accounts.google.com')
        ->and(session(RedirectController::WORKSPACE_SESSION_KEY))->toBe($workspace->getKey());
});

it('does not count disconnected mailboxes against the limit', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();
    ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->count(10)->disconnected()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
    ]));
    $this->actingAs($user);

    $location = (string) $this->get(MailboxOAuthWorkspace::redirectUrl('gmail', $workspace))
        ->headers->get('Location');

    expect($location)->toContain('accounts.google.com');
});
