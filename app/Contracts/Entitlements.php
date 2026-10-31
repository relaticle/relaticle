<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Workspace;
use Relaticle\Chat\Support\ModelDescriptor;

interface Entitlements
{
    public function metersCredits(Workspace $workspace): bool;

    public function creditAllowance(Workspace $workspace): int;

    public function allowsModel(?Workspace $workspace, ModelDescriptor $model): bool;

    public function chatRateLimit(Workspace $workspace): int;

    public function allowsAccess(Workspace $workspace): bool;
}
