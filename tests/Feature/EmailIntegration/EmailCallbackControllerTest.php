<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Relaticle\EmailIntegration\Controllers\CallbackController;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;

mutates(CallbackController::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);
});

it('redirects with a flashed error when Socialite throws InvalidStateException', function (): void {
    Socialite::shouldReceive('driver')->andReturnSelf();
    Socialite::shouldReceive('user')->andThrow(new InvalidStateException);

    $response = $this->get(route('email-accounts.callback', ['provider' => 'gmail']));

    $response->assertRedirect(EmailAccountsPage::getUrl([
        'tenant' => $this->user->currentTeam->slug,
    ], panel: 'app'));
    $response->assertSessionHas('error', 'Your sign-in session expired. Please reconnect the account.');
});

it('redirects with a generic error when Socialite throws any other exception', function (): void {
    Socialite::shouldReceive('driver')->andReturnSelf();
    Socialite::shouldReceive('user')->andThrow(new RuntimeException('boom'));

    $response = $this->get(route('email-accounts.callback', ['provider' => 'gmail']));

    $response->assertRedirect();
    $response->assertSessionHas('error', 'We could not connect that account. Please try again.');
});
