<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Login;
use App\Models\User;

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
