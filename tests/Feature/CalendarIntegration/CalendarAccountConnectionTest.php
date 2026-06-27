<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

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

    Socialite::fake('google', $social);

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']).'?capability=calendar')
        ->assertRedirect();

    $account = ConnectedAccount::query()->where('email_address', 'user@example.com')->firstOrFail();
    expect($account->hasCalendar())->toBeTrue();

    Bus::assertDispatched(InitialCalendarSyncJob::class, fn ($job): bool => $job->connectedAccount->is($account));
});
