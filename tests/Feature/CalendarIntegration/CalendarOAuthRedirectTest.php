<?php

declare(strict_types=1);

use App\Models\User;

it('includes calendar scope when capability=calendar is requested', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $response = $this->get(route('email-accounts.redirect', ['provider' => 'gmail']).'?capability=calendar');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain(urlencode('https://www.googleapis.com/auth/calendar.readonly'));
});
