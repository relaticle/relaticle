<?php

declare(strict_types=1);

use App\Contracts\Entitlements;
use App\Features\Billing;
use App\Services\SelfHostedEntitlements;
use Laravel\Pennant\Feature;
use Relaticle\Cloud\CloudServiceProvider;
use Relaticle\Cloud\Entitlements\PlanEntitlements;

mutates(CloudServiceProvider::class, PlanEntitlements::class);

it('binds plan entitlements when billing is on', function (): void {
    Feature::define(Billing::class, true);

    expect(resolve(Entitlements::class))->toBeInstanceOf(PlanEntitlements::class);
});

it('binds self-hosted entitlements when billing is off', function (): void {
    Feature::define(Billing::class, false);

    expect(resolve(Entitlements::class))->toBeInstanceOf(SelfHostedEntitlements::class);
});
