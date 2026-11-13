<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\AuthMethod;
use App\Features\SignupChallenge;
use App\Models\ActivityLog\Activity;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

final readonly class RecordSignup
{
    private const int USER_AGENT_LENGTH = 500;

    public function execute(User $user, AuthMethod $method, ?string $ip, ?string $userAgent, bool $challenged): void
    {
        if (! Feature::active(SignupChallenge::class)) {
            return;
        }

        activity(Activity::SIGNUP_LOG)
            ->performedOn($user)
            ->causedBy($user)
            ->withProperties([
                'method' => $method->value,
                'ip' => $ip,
                'user_agent' => Str::limit((string) $userAgent, self::USER_AGENT_LENGTH, ''),
                'challenged' => $challenged,
            ])
            ->event('registered')
            ->log('registered');
    }
}
