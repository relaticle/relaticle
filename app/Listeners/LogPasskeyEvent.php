<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;

final class LogPasskeyEvent
{
    public function handle(PasskeyRegistered|PasskeyVerified|PasskeyDeleted $event): void
    {
        $message = match (true) {
            $event instanceof PasskeyRegistered => 'passkey.registered',
            $event instanceof PasskeyVerified => 'passkey.verified',
            $event instanceof PasskeyDeleted => 'passkey.deleted',
        };

        Log::info($message, [
            'user_id' => $event->user->getAuthIdentifier(),
            'passkey_id' => $event->passkey->id,
            'ip' => request()->ip(),
        ]);
    }
}
