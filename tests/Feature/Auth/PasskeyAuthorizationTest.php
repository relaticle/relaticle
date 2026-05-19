<?php

declare(strict_types=1);

use App\Http\Responses\PasskeyLoginResponse;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

it('binds PasskeyLoginResponse to the contract', function (): void {
    expect(app(PasskeyLoginResponseContract::class))->toBeInstanceOf(PasskeyLoginResponse::class);
});

it('allows passkey login for any user by default', function (): void {
    $user = User::factory()->create();
    $passkey = Passkey::create([
        'user_id' => $user->id,
        'name' => 'Test',
        'credential_id' => 'authorize-test-'.uniqid(),
        'credential' => [],
    ]);

    expect(Passkeys::allowsLogin(Request::create('/passkeys/login', 'POST'), $passkey))->toBeTrue();
});
