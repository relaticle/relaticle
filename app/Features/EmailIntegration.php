<?php

declare(strict_types=1);

namespace App\Features;

final readonly class EmailIntegration
{
    public function resolve(): bool
    {
        return (bool) config('relaticle.features.email_integration', true);
    }
}
