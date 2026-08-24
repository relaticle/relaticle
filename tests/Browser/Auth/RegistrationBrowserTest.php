<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Register;
use App\Models\User;

mutates(Register::class);

it('completes a registration after arriving via the login page link', function (): void {
    // With the gate off this walks the funnel without ever touching the spam
    // check, which is the one thing only a browser can prove here: the honeypot
    // is rendered by a Blade string inside a Filament schema and hydrated onto a
    // Wireable under a wire:navigate panel. If any link in that chain breaks,
    // protectAgainstSpam() sees an empty valid_from and aborts every real signup.
    config(['honeypot.enabled' => true]);

    $page = $this->visit('/app/login')
        ->click('a[href*="register"]')
        ->assertPathContains('/register')
        ->type('[id="form.name"]', 'Jane Doe')
        ->type('[id="form.email"]', 'jane-spa-register@gmail.com')
        ->type('[id="form.password"]', 'Password123!')
        ->type('[id="form.passwordConfirmation"]', 'Password123!');

    // honeypot.amount_of_seconds rejects a form submitted sooner than a person
    // could have filled it. The driver types faster than that, so without this
    // the test would fail on the timing rule and tell us nothing about the
    // wiring it is here to prove.
    $page->wait(2);

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
