<?php

declare(strict_types=1);

namespace App\Features;

final readonly class OnboardSeed
{
    public function resolve(): bool
    {
        return (bool) config('relaticle.features.onboard_seed', true);
    }
}
