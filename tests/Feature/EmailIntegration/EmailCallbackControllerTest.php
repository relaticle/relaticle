<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Bus;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Relaticle\EmailIntegration\Controllers\CallbackController;
use Relaticle\EmailIntegration\Controllers\RedirectController;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
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

it('returns to the page that started the connect with a success notification', function (): void {
    Bus::fake();

    Socialite::fake('gmail', connectedGmailUser());
    bindMailboxOAuthWorkspace($this->user);
    session()->put(RedirectController::RETURN_URL_SESSION_KEY, 'https://relaticle.test/app/acme/email');

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))
        ->assertRedirect('https://relaticle.test/app/acme/email');

    Notification::assertNotified(__('filament/pages/email-accounts.notifications.connected.title'));

    expect(session()->has(RedirectController::RETURN_URL_SESSION_KEY))->toBeFalse();
});

it('lands on the account settings after a connect with no return page', function (): void {
    Bus::fake();

    Socialite::fake('gmail', connectedGmailUser());
    bindMailboxOAuthWorkspace($this->user);

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))
        ->assertRedirect(EmailAccountsPage::getUrl([
            'tenant' => $this->user->currentWorkspace->slug,
        ], panel: 'app'));

    Notification::assertNotified(__('filament/pages/email-accounts.notifications.connected.title'));
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
