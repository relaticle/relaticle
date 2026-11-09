<?php

declare(strict_types=1);

use App\Contracts\Billing as BillingContract;
use App\Features\Billing as BillingFeature;
use App\Http\Middleware\EnsureHostedWorkspaceAccess;
use App\Models\User;
use App\Services\NoBilling;
use App\Services\SelfHostedEntitlements;
use Laravel\Pennant\Feature;

mutates(EnsureHostedWorkspaceAccess::class, SelfHostedEntitlements::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->actingAs($this->user);
});

it('does not require hosted billing on self-hosted installations', function (): void {
    Feature::define(BillingFeature::class, false);

    $this->get(route('filament.app.pages.dashboard', ['tenant' => $this->workspace->slug]))
        ->assertOk();
});

it('answers 402 when billing is on but no provider offers a billing page', function (): void {
    withHostedBilling();
    app()->instance(BillingContract::class, new NoBilling);
    $this->workspace->forceFill(['trial_ends_at' => now()->subDay()])->save();

    $this->get(route('filament.app.pages.dashboard', ['tenant' => $this->workspace->slug]))
        ->assertStatus(402);
});
