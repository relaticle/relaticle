<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

mutates(Login::class);

it('user can log in and reach the dashboard', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    loginViaBrowser($user)
        ->assertPathIs("/app/{$team->slug}");
});

it('reveals the password field only after continue', function (): void {
    $user = User::factory()->withTeam()->create();

    $this->visit('/app/login')
        ->assertMissing('[id="form.password"]')
        ->type('[id="form.email"]', $user->email)
        ->click('button[type="submit"]')
        ->assertVisible('[id="form.password"]');
});

it('waits for explicit verification after entering all six digits', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    $code = resolve(Google2FA::class)->getCurrentOtp($secret);

    $page = loginViaBrowser($user)
        ->assertPathIs('/two-factor-challenge')
        ->assertSee($user->email)
        ->assertVisible('input[autocomplete="one-time-code"]:focus');

    $page->type('input[autocomplete="one-time-code"]', $code)
        ->assertSee(__('auth.mfa.switch_account'))
        ->click('Verify')
        ->assertPathIs('/app/'.$user->currentTeam->slug)
        ->assertNoJavaScriptErrors();
});
