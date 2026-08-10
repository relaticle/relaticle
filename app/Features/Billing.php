<?php

declare(strict_types=1);

namespace App\Features;

final readonly class Billing
{
    public function resolve(): bool
    {
        return (bool) config('relaticle.features.billing', false);
    }
}
