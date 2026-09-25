<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Policies;

final class AiCreditBalancePolicy
{
    public function viewAny(): bool
    {
        return true;
    }

    public function view(): bool
    {
        return true;
    }

    public function create(): bool
    {
        return false;
    }

    public function update(): bool
    {
        return true;
    }

    public function delete(): bool
    {
        return false;
    }
}
