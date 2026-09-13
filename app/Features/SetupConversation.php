<?php

declare(strict_types=1);

namespace App\Features;

final readonly class SetupConversation
{
    public function resolve(): bool
    {
        return (bool) config('relaticle.features.setup_conversation', false);
    }
}
