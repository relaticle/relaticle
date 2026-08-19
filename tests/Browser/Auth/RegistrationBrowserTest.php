<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Register;
use App\Models\User;

mutates(Register::class);

it('completes a registration after arriving via the login page link', function (): void {
    $page = $this->visit('/app/login')
        ->click('a[href*="register"]')
        ->assertPathContains('/register')
        ->type('[id="form.name"]', 'Jane Doe')
        ->type('[id="form.email"]', 'jane-spa-register@gmail.com')
        ->type('[id="form.password"]', 'Password123!')
        ->type('[id="form.passwordConfirmation"]', 'Password123!');

    $page->click('button[type="submit"]');

    foreach (range(1, 30) as $attempt) {
        if ($page->script('window.location.pathname') !== '/app/register') {
            break;
        }

        $page->wait(0.5);
    }

    expect($page->script('window.location.pathname'))->not->toBe('/app/register')
        ->and(User::where('email', 'jane-spa-register@gmail.com')->exists())->toBeTrue();
});
