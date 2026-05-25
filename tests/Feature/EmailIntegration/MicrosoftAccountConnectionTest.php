<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;

it('resolves the azure socialite driver', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    expect(fn () => Socialite::driver('azure'))->not->toThrow(Throwable::class);
});
