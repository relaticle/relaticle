<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Controllers\CallbackController;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Livewire\EmailComposer;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

mutates(CallbackController::class, EmailComposer::class);

it('flips calendar capability and dispatches InitialCalendarSyncJob on calendar grant', function (): void {
    Bus::fake();

    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $social = new SocialiteUser;
    $social->id = 'google-123';
    $social->email = 'user@example.com';
    $social->name = 'Demo';
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/calendar.readonly',
    ];

    Socialite::fake('gmail', $social);

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))
        ->assertRedirect();

    $account = ConnectedAccount::query()->where('email_address', 'user@example.com')->firstOrFail();
    expect($account->hasCalendar())->toBeTrue();

    Bus::assertDispatched(InitialCalendarSyncJob::class, fn ($job): bool => $job->connectedAccount->is($account));
});

it('enables calendar when Google grants calendar.events without calendar.readonly', function (): void {
    Bus::fake();

    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $social = new SocialiteUser;
    $social->id = 'google-events';
    $social->email = 'events@example.com';
    $social->name = 'Demo';
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/calendar.events',
    ];

    Socialite::fake('gmail', $social);

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))
        ->assertRedirect();

    $account = ConnectedAccount::query()->where('email_address', 'events@example.com')->firstOrFail();
    expect($account->hasCalendar())->toBeTrue();

    Bus::assertDispatched(InitialCalendarSyncJob::class, fn ($job): bool => $job->connectedAccount->is($account));
});

it('connects mail without calendar when the user leaves both calendar scopes unchecked', function (): void {
    Bus::fake();

    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $social = new SocialiteUser;
    $social->id = 'google-mail-only';
    $social->email = 'mail-only@example.com';
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
        ->assertRedirect();

    $account = ConnectedAccount::query()->where('email_address', 'mail-only@example.com')->firstOrFail();
    expect($account->hasCalendar())->toBeFalse();

    Bus::assertNotDispatched(InitialCalendarSyncJob::class);
});

it('records send as missing when Google does not grant gmail.send', function (): void {
    Bus::fake();

    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $social = new SocialiteUser;
    $social->id = 'google-no-send';
    $social->email = 'no-send@example.com';
    $social->name = 'Demo';
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/calendar.readonly',
    ];

    Socialite::fake('gmail', $social);

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))
        ->assertRedirect();

    $account = ConnectedAccount::query()->where('email_address', 'no-send@example.com')->firstOrFail();

    expect($account->hasSend())->toBeFalse()
        ->and($account->hasEmail())->toBeTrue();
});

it('turns send back on after reconnecting with gmail.send', function (): void {
    Bus::fake();

    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($user->currentTeam);

    $connect = function (array $scopes) use ($user): ConnectedAccount {
        $social = new SocialiteUser;
        $social->id = 'google-reconnect-send';
        $social->email = 'reconnect-send@example.com';
        $social->name = 'Demo';
        $social->token = 'access-token';
        $social->refreshToken = 'refresh-token';
        $social->expiresIn = 3600;
        $social->approvedScopes = $scopes;

        Socialite::fake('gmail', $social);

        $this->get(route('email-accounts.callback', ['provider' => 'gmail']))->assertRedirect();

        return ConnectedAccount::query()
            ->where('user_id', $user->getKey())
            ->where('email_address', 'reconnect-send@example.com')
            ->firstOrFail();
    };

    expect($connect([
        'https://www.googleapis.com/auth/gmail.readonly',
    ])->hasSend())->toBeFalse();

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->assertSee(__('filament/emails/composer.grant_send.description'))
        ->assertDontSee(__('filament/emails/composer.actions.send'));

    expect($connect([
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/gmail.send',
    ])->hasSend())->toBeTrue();

    Livewire::test(EmailComposer::class)
        ->dispatch('composer:open')
        ->assertSee(__('filament/emails/composer.actions.send'))
        ->assertDontSee(__('filament/emails/composer.grant_send.description'));
});
