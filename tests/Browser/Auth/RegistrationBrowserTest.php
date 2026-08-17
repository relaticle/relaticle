<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Register;
use App\Models\User;
use RyanChandler\LaravelCloudflareTurnstile\Facades\Turnstile;

mutates(Register::class);

it('completes a turnstile-protected registration after arriving via the login page link', function (): void {
    config([
        'services.turnstile.key' => '1x00000000000000000000AA',
        'services.turnstile.secret' => '1x0000000000000000000000000000000AA',
    ]);

    Turnstile::fake();

    $page = $this->visit('/app/login')
        ->click('a[href*="register"]')
        ->assertPathContains('/register')
        ->type('[id="form.name"]', 'Jane Doe')
        ->type('[id="form.email"]', 'jane-spa-turnstile@gmail.com')
        ->type('[id="form.password"]', 'Password123!')
        ->type('[id="form.passwordConfirmation"]', 'Password123!');

    $token = '';

    foreach (range(1, 30) as $attempt) {
        $token = (string) $page->script("document.querySelector('input[name=\"cf-turnstile-response\"]')?.value ?? ''");

        if ($token !== '') {
            break;
        }

        $page->wait(0.5);
    }

    expect($token)->not->toBe('', 'The turnstile widget never issued a token.');

    $page->click('button[type="submit"]');

    foreach (range(1, 30) as $attempt) {
        if ($page->script('window.location.pathname') !== '/app/register') {
            break;
        }

        $page->wait(0.5);
    }

    expect($page->script('window.location.pathname'))->not->toBe('/app/register')
        ->and(User::where('email', 'jane-spa-turnstile@gmail.com')->exists())->toBeTrue();
});
