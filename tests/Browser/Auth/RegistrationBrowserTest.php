<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;

mutates(Login::class);

it('completes a signup from the unified login page', function (): void {
    config(['honeypot.enabled' => true]);

    $email = 'jane-spa-signup-'.uniqid().'@gmail.com';

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $email);

    $page->wait(2);

    $page->click('button[type="submit"]')
        ->assertVisible('[id="form.password"]')
        ->type('[id="form.password"]', 'Password123!');

    $page->wait(2);

    $page->click('button[type="submit"]')
        ->assertPathIs('/app/email-verification/prompt');

    expect(User::where('email', $email)->exists())->toBeTrue();
});

it('completes a signup through the turnstile challenge without showing a widget', function (): void {
    config([
        'honeypot.enabled' => true,
        'relaticle.features.signup_challenge' => true,
        'services.turnstile.key' => '1x00000000000000000000AA',
        'services.turnstile.secret' => '1x0000000000000000000000000000000AA',
    ]);
    Feature::flushCache();
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

    $email = 'jane-turnstile-signup-'.uniqid().'@gmail.com';

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $email);

    $page->wait(2);

    $page->click('button[type="submit"]')
        ->assertVisible('[id="form.password"]')
        ->assertScript('document.querySelector(\'[x-ref="widget"]\').closest(\'.fi-grid-col\').classList.contains(\'fi-hidden\')', true)
        ->type('[id="form.password"]', 'Password123!');

    $page->wait(3);

    $page->click('button[type="submit"]')
        ->assertPathIs('/app/email-verification/prompt');

    expect(User::where('email', $email)->exists())->toBeTrue();
});
