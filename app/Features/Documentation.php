<?php

declare(strict_types=1);

namespace App\Features;

final readonly class Documentation
{
    public function resolve(): bool
    {
        return (bool) config('relaticle.features.documentation', true);
    }
}
