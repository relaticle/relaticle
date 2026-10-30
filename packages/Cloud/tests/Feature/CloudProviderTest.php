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

it('keeps the pre-move managed plan invoice mail name loadable for queued jobs', function (): void {
    expect(class_exists('Relaticle\\SystemAdmin\\Mail\\ManagedPlanInvoiceMail'))->toBeTrue();
});

it('points the legal links at its own privacy, terms and contact pages', function (): void {
    expect(config('relaticle.legal.privacy_url'))->toBe(route('policy.show'))
        ->and(config('relaticle.legal.terms_url'))->toBe(route('terms.show'))
        ->and(config('relaticle.legal.contact_url'))->toBe(route('contact'));
});

it('renders the sign-in footer with its legal links', function (): void {
    $this->get(url()->getAppUrl('login'))
        ->assertOk()
        ->assertSee('href="'.route('terms.show').'"', escape: false)
        ->assertSee('href="'.route('policy.show').'"', escape: false)
        ->assertSee('href="'.route('contact').'"', escape: false);
});

it('keeps a legal link the operator configured', function (): void {
    config()->set('relaticle.legal.privacy_url', 'https://legal.example.test/privacy');
    config()->set('relaticle.legal.terms_url', null);

    (new CloudServiceProvider(app()))->boot();

    expect(config('relaticle.legal.privacy_url'))->toBe('https://legal.example.test/privacy')
        ->and(config('relaticle.legal.terms_url'))->toBe(route('terms.show'));
});

it('links the documentation to its own public root', function (): void {
    expect(config('documentation.public_url'))->toBe(url()->getPublicUrl());
});
