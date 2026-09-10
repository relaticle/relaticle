<?php

declare(strict_types=1);

namespace App\Features;

final readonly class AccountDeletion
{
    public function resolve(): bool
    {
        return (bool) config('relaticle.features.account_deletion', false);
    }
}
