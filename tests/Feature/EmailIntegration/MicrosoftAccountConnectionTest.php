<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Bus;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Relaticle\EmailIntegration\Actions\ConnectAccountAction;
use Relaticle\EmailIntegration\Controllers\CallbackController;
use Relaticle\EmailIntegration\Controllers\RedirectController;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\RelinkMailboxHistoryJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

mutates(AppServiceProvider::class);
mutates(CallbackController::class);
mutates(ConnectAccountAction::class);
mutates(RedirectController::class);

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
    bindMailboxOAuthWorkspace($user);

    $this->get(route('email-accounts.callback', ['provider' => 'azure']))
        ->assertRedirect();

    $account = ConnectedAccount::query()
        ->where('email_address', 'ms@example.com')
        ->where('provider', EmailProvider::AZURE)
        ->firstOrFail();

    expect($account->hasCalendar())->toBeTrue()
        ->and($account->capabilities['email'])->toBeTrue()
        ->and($account->hasSend())->toBeTrue()
        ->and($user->currentTeam->fresh()->contact_creation_mode)->toBe(ContactCreationMode::Selective);

    Bus::assertDispatched(InitialCalendarSyncJob::class, fn (InitialCalendarSyncJob $job): bool => $job->connectedAccount->is($account));
    Bus::assertDispatched(InitialEmailSyncJob::class, fn (InitialEmailSyncJob $job): bool => $job->connectedAccount->is($account));
    Bus::assertDispatched(RelinkMailboxHistoryJob::class, fn (RelinkMailboxHistoryJob $job): bool => $job->connectedAccount->is($account));
});

it('flips calendar capability when Graph grants Calendars.ReadWrite without Calendars.Read', function (): void {
    Bus::fake();

    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $social = new SocialiteUser;
    $social->id = 'azure-write';
    $social->email = 'ms-write@example.com';
    $social->name = 'MS Demo';
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = [
        'https://graph.microsoft.com/Mail.Read',
        'https://graph.microsoft.com/Calendars.ReadWrite',
        'offline_access',
    ];

    Socialite::fake('azure', $social);
    bindMailboxOAuthWorkspace($user);

    $this->get(route('email-accounts.callback', ['provider' => 'azure']))
        ->assertRedirect();

    $account = ConnectedAccount::query()
        ->where('email_address', 'ms-write@example.com')
        ->where('provider', EmailProvider::AZURE)
        ->firstOrFail();

    expect($account->hasCalendar())->toBeTrue();

    Bus::assertDispatched(InitialCalendarSyncJob::class, fn (InitialCalendarSyncJob $job): bool => $job->connectedAccount->is($account));
});

it('records send as missing when Graph does not grant Mail.Send', function (): void {
    Bus::fake();

    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $social = new SocialiteUser;
    $social->id = 'azure-no-send';
    $social->email = 'ms-no-send@example.com';
    $social->name = 'MS Demo';
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = [
        'https://graph.microsoft.com/Mail.Read',
        'https://graph.microsoft.com/Calendars.Read',
        'offline_access',
    ];

    Socialite::fake('azure', $social);
    bindMailboxOAuthWorkspace($user);

    $this->get(route('email-accounts.callback', ['provider' => 'azure']))
        ->assertRedirect();

    $account = ConnectedAccount::query()
        ->where('email_address', 'ms-no-send@example.com')
        ->where('provider', EmailProvider::AZURE)
        ->firstOrFail();

    expect($account->hasSend())->toBeFalse()
        ->and($account->hasEmail())->toBeTrue();
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

        Socialite::fake('gmail', $social);
        bindMailboxOAuthWorkspace($user);

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

it('dispatches history import when a disconnected account is reconnected', function (): void {
    Bus::fake();

    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->trashed()->create([
        'user_id' => $user->getKey(),
        'team_id' => $user->current_team_id,
        'email_address' => 'again@example.com',
        'provider' => EmailProvider::GMAIL,
        'provider_account_id' => 'gmail-reconnect-import',
    ]));

    $social = new SocialiteUser;
    $social->id = 'gmail-reconnect-import';
    $social->email = 'again@example.com';
    $social->name = 'Demo';
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/gmail.send',
    ];

    Socialite::fake('gmail', $social);
    bindMailboxOAuthWorkspace($user);
    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))->assertRedirect();

    expect($account->refresh()->trashed())->toBeFalse();

    Bus::assertDispatched(InitialEmailSyncJob::class, fn (InitialEmailSyncJob $job): bool => $job->connectedAccount->is($account));
    Bus::assertDispatched(RelinkMailboxHistoryJob::class, fn (RelinkMailboxHistoryJob $job): bool => $job->connectedAccount->is($account));
});

it('connects the mailbox to the workspace where authorization started after the current workspace changes', function (): void {
    Bus::fake();

    $user = User::factory()->withTeam()->create();
    $initiatingTeam = $user->currentTeam;
    $otherTeam = Team::factory()->create(['user_id' => $user->getKey()]);
    $user->teams()->attach($otherTeam, ['role' => 'admin']);

    $this->actingAs($user);

    config()->set('services.gmail.client_id', 'gmail-client-id');
    config()->set('services.gmail.client_secret', 'gmail-client-secret');
    config()->set('services.gmail.redirect', 'http://localhost/email-accounts/callback/gmail');

    $this->get(route('email-accounts.redirect', ['provider' => 'gmail']))
        ->assertRedirect();

    $user->forceFill(['current_team_id' => $otherTeam->getKey()])->save();
    $user->unsetRelation('currentTeam');

    $social = new SocialiteUser;
    $social->id = 'gmail-workspace-bind';
    $social->email = 'bind@example.com';
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
            'tenant' => $initiatingTeam->slug,
        ], panel: 'app'));

    $this->assertDatabaseHas(ConnectedAccount::class, [
        'email_address' => 'bind@example.com',
        'team_id' => $initiatingTeam->getKey(),
    ]);
    $this->assertDatabaseMissing(ConnectedAccount::class, [
        'email_address' => 'bind@example.com',
        'team_id' => $otherTeam->getKey(),
    ]);
});

it('does not connect a mailbox when the user left the authorizing workspace', function (): void {
    Bus::fake();

    $user = User::factory()->withTeam()->create();
    $foreignTeam = Team::factory()->create();
    $user->teams()->attach($foreignTeam, ['role' => 'admin']);
    $user->forceFill(['current_team_id' => $foreignTeam->getKey()])->save();
    $user->unsetRelation('currentTeam');

    $this->actingAs($user);

    config()->set('services.gmail.client_id', 'gmail-client-id');
    config()->set('services.gmail.client_secret', 'gmail-client-secret');
    config()->set('services.gmail.redirect', 'http://localhost/email-accounts/callback/gmail');

    $this->get(route('email-accounts.redirect', ['provider' => 'gmail']))
        ->assertRedirect();

    $user->teams()->detach($foreignTeam);
    $user->forceFill(['current_team_id' => $user->ownedTeams()->first()?->getKey()])->save();
    $user->unsetRelation('currentTeam');
    $user->unsetRelation('teams');

    $social = new SocialiteUser;
    $social->id = 'gmail-left-workspace';
    $social->email = 'left@example.com';
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
        ->assertRedirect()
        ->assertSessionHas('error', 'Your sign-in session expired. Please reconnect the account.');

    $this->assertDatabaseMissing(ConnectedAccount::class, [
        'email_address' => 'left@example.com',
    ]);
});

it('stores a separate connected account when the same mailbox is connected in a second workspace', function (): void {
    Bus::fake();

    $user = User::factory()->withTeam()->create();
    $firstTeam = $user->currentTeam;
    $secondTeam = Team::factory()->create(['user_id' => $user->getKey()]);
    $user->teams()->attach($secondTeam, ['role' => 'admin']);

    $connect = function () use ($user): void {
        $social = new SocialiteUser;
        $social->id = 'gmail-shared-mailbox';
        $social->email = 'shared@example.com';
        $social->name = 'Demo';
        $social->token = 'access-token';
        $social->refreshToken = 'refresh-token';
        $social->expiresIn = 3600;
        $social->approvedScopes = [
            'https://www.googleapis.com/auth/gmail.readonly',
            'https://www.googleapis.com/auth/gmail.send',
        ];

        Socialite::fake('gmail', $social);
        bindMailboxOAuthWorkspace($user);

        $this->actingAs($user)
            ->get(route('email-accounts.callback', ['provider' => 'gmail']))
            ->assertRedirect();
    };

    $connect();

    $user->forceFill(['current_team_id' => $secondTeam->getKey()])->save();
    $user->unsetRelation('currentTeam');
    $connect();

    $accounts = ConnectedAccount::query()
        ->where('user_id', $user->getKey())
        ->where('email_address', 'shared@example.com')
        ->get();

    expect($accounts)->toHaveCount(2)
        ->and($accounts->pluck('team_id')->all())->toEqualCanonicalizing([
            $firstTeam->getKey(),
            $secondTeam->getKey(),
        ]);
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

        Socialite::fake('gmail', $social);
        bindMailboxOAuthWorkspace($user);

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
