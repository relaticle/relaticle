<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\WorkspaceSetupStep;
use App\Features\Billing;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Relaticle\EmailIntegration\Controllers\CallbackController;
use Relaticle\EmailIntegration\Controllers\RedirectController;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Filament\Pages\MailboxSharingPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

mutates(CallbackController::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentWorkspace);
});

it('redirects with a flashed error when Socialite throws InvalidStateException', function (): void {
    Socialite::shouldReceive('driver')->andReturnSelf();
    Socialite::shouldReceive('user')->andThrow(new InvalidStateException);

    $response = $this->get(route('email-accounts.callback', ['provider' => 'gmail']));

    $response->assertRedirect(EmailAccountsPage::getUrl([
        'tenant' => $this->user->currentWorkspace->slug,
    ], panel: 'app'));
    $response->assertSessionHas('error', 'Your sign-in session expired. Please reconnect the account.');
});

it('does not connect a mailbox when the authorizing workspace is missing from the session', function (): void {
    Bus::fake();

    $social = new SocialiteUser;
    $social->id = 'gmail-missing-workspace';
    $social->email = 'missing-workspace@example.com';
    $social->name = 'Demo';
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/gmail.send',
    ];

    Socialite::fake('gmail', $social);

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))
        ->assertRedirect(EmailAccountsPage::getUrl([
            'tenant' => $this->user->currentWorkspace->slug,
        ], panel: 'app'))
        ->assertSessionHas('error', 'Your sign-in session expired. Please reconnect the account.');

    $this->assertDatabaseMissing(ConnectedAccount::class, [
        'email_address' => 'missing-workspace@example.com',
    ]);
});

it('redirects with a generic error when Socialite throws any other exception', function (): void {
    Socialite::shouldReceive('driver')->andReturnSelf();
    Socialite::shouldReceive('user')->andThrow(new RuntimeException('boom'));

    $response = $this->get(route('email-accounts.callback', ['provider' => 'gmail']));

    $response->assertRedirect();
    $response->assertSessionHas('error', 'We could not connect that account. Please try again.');
});

it('tells an owner who cancels at the provider that nothing was connected', function (): void {
    bindMailboxOAuthWorkspace($this->user);
    session()->put(RedirectController::RETURN_URL_SESSION_KEY, 'https://relaticle.test/app/acme/setup');
    Log::spy();

    $this->get(route('email-accounts.callback', ['provider' => 'gmail', 'error' => 'access_denied']))
        ->assertRedirect('https://relaticle.test/app/acme/setup');

    Notification::assertNotified(
        Notification::make()->title(__('filament/pages/email-accounts.notifications.cancelled.title'))->warning(),
    );

    Log::shouldNotHaveReceived('error');
});

it('asks a first connect for sharing while keeping its return page and success notification', function (): void {
    Bus::fake();

    Socialite::fake('gmail', connectedGmailUser());
    bindMailboxOAuthWorkspace($this->user);
    session()->put(RedirectController::RETURN_URL_SESSION_KEY, 'https://relaticle.test/app/acme/email');

    $response = $this->get(route('email-accounts.callback', ['provider' => 'gmail']));

    $response->assertRedirect(MailboxSharingPage::getUrl([
        'mailbox' => ConnectedAccount::query()->where('email_address', 'connected@example.com')->sole()->getKey(),
        'return' => 'https://relaticle.test/app/acme/email',
    ]));

    Notification::assertNotified(__('filament/pages/email-accounts.notifications.connected.title'));

    expect(session()->has(RedirectController::RETURN_URL_SESSION_KEY))->toBeFalse();
});

it('returns a failed connect to the page that started it', function (): void {
    bindMailboxOAuthWorkspace($this->user);
    session()->put(RedirectController::RETURN_URL_SESSION_KEY, 'https://relaticle.test/app/acme/setup');

    Socialite::shouldReceive('driver')->andReturnSelf();
    Socialite::shouldReceive('user')->andThrow(new InvalidStateException);

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))
        ->assertRedirect('https://relaticle.test/app/acme/setup')
        ->assertSessionMissing('error');

    Notification::assertNotified(
        Notification::make()->title('Your sign-in session expired. Please reconnect the account.')->danger(),
    );

    expect(session()->has(RedirectController::RETURN_URL_SESSION_KEY))->toBeFalse();
});

it('returns to the page that started the connect when the mail read permission was declined', function (): void {
    Bus::fake();

    $social = connectedGmailUser();
    $social->approvedScopes = ['openid', 'https://www.googleapis.com/auth/gmail.send'];

    Socialite::fake('gmail', $social);
    bindMailboxOAuthWorkspace($this->user);
    session()->put(RedirectController::RETURN_URL_SESSION_KEY, 'https://relaticle.test/app/acme/setup');

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))
        ->assertRedirect('https://relaticle.test/app/acme/setup')
        ->assertSessionMissing('error');

    Notification::assertNotified(
        Notification::make()->title('Relaticle needs permission to read your mail. Reconnect and allow every permission.')->danger(),
    );

    expect(session()->has(RedirectController::RETURN_URL_SESSION_KEY))->toBeFalse();
});

it('keeps the account settings as the destination after a first sharing answer with no return page', function (): void {
    Bus::fake();

    Socialite::fake('gmail', connectedGmailUser());
    bindMailboxOAuthWorkspace($this->user);

    $response = $this->get(route('email-accounts.callback', ['provider' => 'gmail']));

    $response->assertRedirect(MailboxSharingPage::getUrl([
        'mailbox' => ConnectedAccount::query()->where('email_address', 'connected@example.com')->sole()->getKey(),
        'return' => EmailAccountsPage::getUrl(tenant: $this->user->currentWorkspace),
    ]));

    Notification::assertNotified(__('filament/pages/email-accounts.notifications.connected.title'));
});

it('does not connect a gmail mailbox when the mail read permission was not granted', function (): void {
    Bus::fake();

    $social = connectedGmailUser();
    $social->approvedScopes = [
        'openid',
        'https://www.googleapis.com/auth/userinfo.email',
        'https://www.googleapis.com/auth/gmail.send',
    ];

    Socialite::fake('gmail', $social);
    bindMailboxOAuthWorkspace($this->user);

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))
        ->assertRedirect(EmailAccountsPage::getUrl([
            'tenant' => $this->user->currentWorkspace->slug,
        ], panel: 'app'))
        ->assertSessionHas('error', 'Relaticle needs permission to read your mail. Reconnect and allow every permission.');

    $this->assertDatabaseMissing(ConnectedAccount::class, [
        'email_address' => 'connected@example.com',
    ]);

    Bus::assertNothingDispatched();
});

it('does not connect a new mailbox when the workspace has reached its limit', function (): void {
    withHostedBilling();
    Bus::fake();

    $workspace = $this->user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();
    ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->count(10)->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $this->user->getKey(),
    ]));

    Socialite::fake('gmail', connectedGmailUser());
    bindMailboxOAuthWorkspace($this->user);

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))
        ->assertRedirect(EmailAccountsPage::getUrl(['tenant' => $workspace->slug], panel: 'app'))
        ->assertSessionHas('error', 'Mailbox limit reached. This workspace can connect 10 mailboxes. Upgrade to connect more.');

    $this->assertDatabaseMissing(ConnectedAccount::class, [
        'email_address' => 'connected@example.com',
    ]);

    Bus::assertNothingDispatched();
});

it('connects a mailbox past every plan limit on a self-hosted install', function (): void {
    Feature::define(Billing::class, false);
    Bus::fake();

    $workspace = $this->user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Free])->save();
    ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->count(60)->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $this->user->getKey(),
    ]));

    Socialite::fake('gmail', connectedGmailUser());
    bindMailboxOAuthWorkspace($this->user);

    $response = $this->get(route('email-accounts.callback', ['provider' => 'gmail']));

    $response->assertRedirect(MailboxSharingPage::getUrl([
        'mailbox' => ConnectedAccount::query()->where('email_address', 'connected@example.com')->sole()->getKey(),
        'return' => EmailAccountsPage::getUrl(tenant: $workspace),
    ]))->assertSessionMissing('error');

    $this->assertDatabaseHas(ConnectedAccount::class, [
        'workspace_id' => $workspace->getKey(),
        'email_address' => 'connected@example.com',
    ]);
});

it('reconnects an existing mailbox when the workspace has reached its limit', function (): void {
    withHostedBilling();
    Bus::fake();

    $workspace = $this->user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();
    ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->count(9)->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $this->user->getKey(),
    ]));
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $this->user->getKey(),
        'email_address' => 'connected@example.com',
        'access_token' => 'stale-token',
    ]));
    $account->forceFill(['sharing_confirmed_at' => now()])->save();

    Socialite::fake('gmail', connectedGmailUser());
    bindMailboxOAuthWorkspace($this->user);

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))
        ->assertRedirect(EmailAccountsPage::getUrl(['tenant' => $workspace->slug], panel: 'app'))
        ->assertSessionMissing('error');

    Notification::assertNotified(__('filament/pages/email-accounts.notifications.connected.title'));

    expect(ConnectedAccount::query()->where('email_address', 'connected@example.com')->count())->toBe(1)
        ->and(ConnectedAccount::query()->where('email_address', 'connected@example.com')->sole()->access_token)->toBe('access-token');
});

it('leaves the onboarding wizard to ask sharing itself', function (): void {
    Bus::fake();
    $this->user->currentWorkspace->forceFill(['onboarding_step' => WorkspaceSetupStep::Email])->save();
    Socialite::fake('gmail', connectedGmailUser());
    bindMailboxOAuthWorkspace($this->user);
    session()->put(RedirectController::RETURN_URL_SESSION_KEY, 'https://relaticle.test/app/acme/setup');

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))
        ->assertRedirect('https://relaticle.test/app/acme/setup');
});

function connectedGmailUser(): SocialiteUser
{
    $social = new SocialiteUser;
    $social->id = 'gmail-connected';
    $social->email = 'connected@example.com';
    $social->name = 'Demo';
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/gmail.send',
    ];

    return $social;
}
