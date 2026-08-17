<?php

declare(strict_types=1);

namespace App\Features;

final readonly class Marketing
{
    public function resolve(): bool
    {
        return (bool) config('relaticle.features.marketing', true);
    }
}
