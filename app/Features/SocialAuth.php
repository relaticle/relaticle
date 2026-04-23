<?php

declare(strict_types=1);

namespace App\Features;

final readonly class SocialAuth
{
    public function resolve(): bool
    {
        return (bool) config('relaticle.features.social_auth', true);
    }
}
