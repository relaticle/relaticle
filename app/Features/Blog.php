<?php

declare(strict_types=1);

namespace App\Features;

final readonly class Blog
{
    public function resolve(): bool
    {
        return (bool) config('relaticle.features.blog', false);
    }
}
