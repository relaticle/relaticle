<?php

declare(strict_types=1);

use App\Models\User;

it('includes calendar scope on the default mailbox connect redirect', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $response = $this->get(route('email-accounts.redirect', ['provider' => 'gmail']));

    $response->assertRedirect();
    expect($response->headers->get('Location'))
        ->toContain(urlencode('https://www.googleapis.com/auth/calendar.events'))
        ->toContain(urlencode('https://www.googleapis.com/auth/calendar.readonly'));
});
