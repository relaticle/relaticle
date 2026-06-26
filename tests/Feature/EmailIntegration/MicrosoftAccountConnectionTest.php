<?php

declare(strict_types=1);

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Bus;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Relaticle\EmailIntegration\Controllers\CallbackController;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

mutates(AppServiceProvider::class);
mutates(CallbackController::class);

it('resolves the azure socialite driver', function (): void {
    expect(fn () => Socialite::driver('azure'))->not->toThrow(Throwable::class);
});

it('stores an azure connected account and flips calendar capability when Graph calendar scope is granted', function (): void {
    Bus::fake();

    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $social = new SocialiteUser;
    $social->id = 'azure-123';
    $social->email = 'ms@example.com';
    $social->name = 'MS Demo';
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = [
        'https://graph.microsoft.com/Mail.Read',
        'https://graph.microsoft.com/Mail.Send',
        'https://graph.microsoft.com/Calendars.Read',
        'offline_access',
    ];

    Socialite::fake('azure', $social);

    $this->get(route('email-accounts.callback', ['provider' => 'azure']))
        ->assertRedirect();

    $account = ConnectedAccount::query()
        ->where('email_address', 'ms@example.com')
        ->where('provider', EmailProvider::AZURE)
        ->firstOrFail();

    expect($account->hasCalendar())->toBeTrue()
        ->and($account->capabilities['email'])->toBeTrue()
        ->and($account->contact_creation_mode)->toBe(ContactCreationMode::Bidirectional);

    Bus::assertDispatched(InitialCalendarSyncJob::class, fn (InitialCalendarSyncJob $job): bool => $job->connectedAccount->is($account));
});

it('preserves the stored refresh token when a reconnect returns none', function (): void {
    Bus::fake();

    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $connect = function (?string $refreshToken) use ($user): ConnectedAccount {
        $social = new SocialiteUser;
        $social->id = 'gmail-reconnect';
        $social->email = 'reconnect@example.com';
        $social->name = 'Demo';
        $social->token = 'access-'.($refreshToken ?? 'none');
        $social->refreshToken = $refreshToken;
        $social->expiresIn = 3600;
        $social->approvedScopes = [
            'https://www.googleapis.com/auth/gmail.readonly',
            'https://www.googleapis.com/auth/gmail.send',
        ];

        Socialite::fake('google', $social);

        $this->get(route('email-accounts.callback', ['provider' => 'gmail']))->assertRedirect();

        return ConnectedAccount::query()
            ->where('user_id', $user->getKey())
            ->where('email_address', 'reconnect@example.com')
            ->firstOrFail();
    };

    $connect('original-refresh');   // first consent issues a refresh token
    $account = $connect(null);      // re-consent returns none — must NOT clobber the stored token

    expect($account->refresh()->refresh_token)->toBe('original-refresh');
});

it('makes the first connected account the default and leaves later connections non-default', function (): void {
    Bus::fake();

    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $connect = function (string $email) use ($user): ConnectedAccount {
        $social = new SocialiteUser;
        $social->id = "gmail-{$email}";
        $social->email = $email;
        $social->name = 'Demo';
        $social->token = 'access-token';
        $social->refreshToken = 'refresh-token';
        $social->expiresIn = 3600;
        $social->approvedScopes = [
            'https://www.googleapis.com/auth/gmail.readonly',
            'https://www.googleapis.com/auth/gmail.send',
        ];

        Socialite::fake('google', $social);

        $this->get(route('email-accounts.callback', ['provider' => 'gmail']))->assertRedirect();

        return ConnectedAccount::query()
            ->where('user_id', $user->getKey())
            ->where('email_address', $email)
            ->firstOrFail();
    };

    $first = $connect('first@example.com');
    $second = $connect('second@example.com');

    expect($first->refresh()->is_default)->toBeTrue()
        ->and($second->refresh()->is_default)->toBeFalse();
});
