<?php

declare(strict_types=1);

use App\Listeners\Email\RecordLoginTimestampListener;
use App\Models\User;
use Illuminate\Auth\Events\Login;

mutates(RecordLoginTimestampListener::class);

test('login event updates last_login_at on the user', function () {
    $user = User::factory()->withTeam()->create(['last_login_at' => null]);

    expect($user->last_login_at)->toBeNull();

    $this->travelTo(now()->setTime(14, 30, 0));

    event(new Login('web', $user, false));

    $user->refresh();

    expect($user->last_login_at)->not->toBeNull()
        ->and($user->last_login_at->format('H:i'))->toBe('14:30');
});

test('login event updates last_login_at for returning users', function () {
    $oldLogin = now()->subDays(10);
    $user = User::factory()->withTeam()->create(['last_login_at' => $oldLogin]);

    $this->travelTo(now());

    event(new Login('web', $user, false));

    $user->refresh();

    expect($user->last_login_at->gt($oldLogin))->toBeTrue();
});

test('same-day re-logins do not write to the database', function () {
    $this->travelTo(now()->setTime(9, 0, 0));

    $user = User::factory()->withTeam()->create(['last_login_at' => now()]);
    $firstLogin = $user->refresh()->last_login_at;

    $this->travelTo(now()->setTime(15, 30, 0));

    event(new Login('web', $user, false));

    expect($user->refresh()->last_login_at->eq($firstLogin))->toBeTrue();
});
