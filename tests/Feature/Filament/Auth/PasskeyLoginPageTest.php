<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Login;

it('renders the Sign in with passkey button via the panel render hook', function (): void {
    $response = $this->get(url()->getAppUrl('login'));

    $response->assertStatus(200);
    $response->assertSee('Sign in with a passkey');
});

it('sets autocomplete=username webauthn on the email field for conditional mediation', function (): void {
    livewire(Login::class)
        ->assertSeeHtml('autocomplete="username webauthn"');
});
