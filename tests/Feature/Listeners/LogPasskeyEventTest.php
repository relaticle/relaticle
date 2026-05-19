<?php

declare(strict_types=1);

use App\Listeners\LogPasskeyEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Passkey;

mutates(LogPasskeyEvent::class);

it('logs a passkey event with user_id, passkey_id, and ip', function (): void {
    $user = User::factory()->create();
    $passkey = Passkey::create([
        'user_id' => $user->id,
        'name' => 'Test',
        'credential_id' => 'log-test-'.uniqid(),
        'credential' => [],
    ]);

    Log::spy();

    event(new PasskeyRegistered($user, $passkey));

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context) use ($user, $passkey): bool {
            return $message === 'passkey.registered'
                && $context['user_id'] === $user->getAuthIdentifier()
                && $context['passkey_id'] === $passkey->id
                && array_key_exists('ip', $context);
        })
        ->once();
});
