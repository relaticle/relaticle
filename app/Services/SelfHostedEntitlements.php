<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Entitlements;
use App\Models\Workspace;
use Relaticle\Chat\Support\ModelDescriptor;

final readonly class SelfHostedEntitlements implements Entitlements
{
    public function metersCredits(Workspace $workspace): bool
    {
        return false;
    }

    public function creditAllowance(Workspace $workspace): int
    {
        return 0;
    }

    public function allowsModel(?Workspace $workspace, ModelDescriptor $model): bool
    {
        return true;
    }

    public function chatRateLimit(Workspace $workspace): int
    {
        return (int) config('chat.rate_limit_per_minute');
    }

    public function allowsAccess(Workspace $workspace): bool
    {
        return true;
    }
}
