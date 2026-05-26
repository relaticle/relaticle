<?php

declare(strict_types=1);

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Bus;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Relaticle\EmailIntegration\Controllers\CallbackController;
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
        ->and($account->capabilities['email'])->toBeTrue();

    Bus::assertDispatched(InitialCalendarSyncJob::class, fn (InitialCalendarSyncJob $job): bool => $job->connectedAccount->is($account));
});
