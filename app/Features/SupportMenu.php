<?php

declare(strict_types=1);

namespace App\Features;

final readonly class SupportMenu
{
    public function resolve(): bool
    {
        return (bool) config('relaticle.features.support_menu', false);
    }
}
