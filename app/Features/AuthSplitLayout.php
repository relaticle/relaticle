<?php

declare(strict_types=1);

namespace App\Features;

final readonly class AuthSplitLayout
{
    public function resolve(): bool
    {
        return (bool) config('relaticle.features.auth_split_layout', false);
    }
}
