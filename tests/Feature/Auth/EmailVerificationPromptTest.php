<?php

declare(strict_types=1);

use App\Models\User;
use App\Providers\FortifyServiceProvider;
use Filament\Facades\Filament;

mutates(FortifyServiceProvider::class);

test('unverified user hitting the Fortify verification-notice route is sent to the Filament prompt', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get('/email/verify')
        ->assertRedirect(Filament::getPanel('app')->getEmailVerificationPromptUrl());
});

test('verified user hitting the Fortify verification-notice route is redirected away from the prompt', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/email/verify');

    $response->assertRedirect();

    expect($response->headers->get('Location'))
        ->not->toBe(Filament::getPanel('app')->getEmailVerificationPromptUrl());
});
