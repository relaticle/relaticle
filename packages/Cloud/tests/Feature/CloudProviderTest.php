<?php

declare(strict_types=1);

use App\Contracts\Billing as BillingContract;
use App\Contracts\Entitlements;
use App\Features\Billing;
use App\Services\NoBilling;
use App\Services\SelfHostedEntitlements;
use Laravel\Pennant\Feature;
use Relaticle\Cloud\Billing\Filament\Billing as BillingPage;
use Relaticle\Cloud\Billing\StripeBilling;
use Relaticle\Cloud\CloudServiceProvider;
use Relaticle\Cloud\Entitlements\PlanEntitlements;

mutates(CloudServiceProvider::class, PlanEntitlements::class, StripeBilling::class, NoBilling::class);

it('binds plan entitlements when billing is on', function (): void {
    Feature::define(Billing::class, true);

    expect(resolve(Entitlements::class))->toBeInstanceOf(PlanEntitlements::class);
});

it('binds self-hosted entitlements when billing is off', function (): void {
    Feature::define(Billing::class, false);

    expect(resolve(Entitlements::class))->toBeInstanceOf(SelfHostedEntitlements::class);
});

it('binds stripe billing when billing is on', function (): void {
    Feature::define(Billing::class, true);

    expect(resolve(BillingContract::class))->toBeInstanceOf(StripeBilling::class);
});

it('binds no billing when billing is off', function (): void {
    Feature::define(Billing::class, false);

    expect(resolve(BillingContract::class))->toBeInstanceOf(NoBilling::class);
});

it('resolves the pre-move billing page component name to the cloud page', function (): void {
    expect(app('livewire.factory')->resolveComponentClass('App\\Filament\\Pages\\Billing'))->toBe(BillingPage::class)
        ->and(app('livewire.factory')->resolveComponentName(BillingPage::class))->toBe(BillingPage::class);
});
