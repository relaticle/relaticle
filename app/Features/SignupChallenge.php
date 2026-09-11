<?php

declare(strict_types=1);

namespace App\Features;

final readonly class SignupChallenge
{
    public function resolve(): bool
    {
        return (bool) config('relaticle.features.signup_challenge', false);
    }
}
