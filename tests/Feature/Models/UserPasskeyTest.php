<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkey;

it('implements PasskeyUser contract', function (): void {
    $user = User::factory()->make();

    expect($user)->toBeInstanceOf(PasskeyUser::class);
});

it('exposes a passkeys hasMany relationship', function (): void {
    $user = User::factory()->make();

    expect($user->passkeys())->toBeInstanceOf(HasMany::class)
        ->and($user->passkeys()->getRelated())->toBeInstanceOf(Passkey::class);
});
