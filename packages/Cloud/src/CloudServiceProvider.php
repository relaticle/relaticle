<?php

declare(strict_types=1);

namespace Relaticle\Cloud;

use App\Contracts\Entitlements;
use App\Features\Billing;
use App\Services\SelfHostedEntitlements;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;
use Relaticle\Cloud\Entitlements\PlanEntitlements;

final class CloudServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Entitlements::class, static fn (Application $app): Entitlements => Feature::active(Billing::class)
            ? $app->make(PlanEntitlements::class)
            : $app->make(SelfHostedEntitlements::class));
    }
}
