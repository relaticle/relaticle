<?php

declare(strict_types=1);

use App\Features\Billing as BillingFeature;
use Laravel\Pennant\Feature;

it('shows the legacy two-card page when billing is off', function (): void {
    Feature::define(BillingFeature::class, false);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee('No per-seat pricing. Ever.')
        ->assertDontSee('Cloud Pro');
});

it('shows the pro tier when billing is on', function (): void {
    Feature::define(BillingFeature::class, true);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee('No per-seat pricing. Ever.')
        ->assertSee('$19')
        ->assertSee('$24')
        ->assertSee('$228 billed yearly')
        ->assertSee('Save 21%')
        ->assertSee('2,000 AI credits')
        ->assertSee('Cloud Pro')
        ->assertSee('Self-Hosted')
        ->assertSee('Start your 14-day trial')
        ->assertDontSee('One workspace price as your team grows')
        ->assertDontSee('300 AI credits')
        ->assertDontSee('Generous free tier');
});

it('emits product json-ld on the pricing page', function (): void {
    $this->get('/pricing')
        ->assertOk()
        ->assertSee('application/ld+json', false)
        ->assertSee('"@type":"Product"', false);
});

it('answers common pricing questions on the page', function (): void {
    $this->get('/pricing')
        ->assertSee(__('Is Relaticle really free to self-host?'))
        ->assertSee(__('Do you charge per seat?'));
});

it('scopes the hosted-plan faq to the pro tier when billing is on', function (): void {
    Feature::define(BillingFeature::class, true);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee(__("What's included in the hosted plan?"))
        ->assertSee(__('What happens after my trial ends?'))
        ->assertSee('2,000-credit')
        ->assertDontSee(__('The hosted Cloud plan is $0/mo and includes unlimited users and data, the 30-tool MCP server, the REST API, all 22 custom field types, multi-team workspaces, zero-downtime updates, automatic daily backups, and email support — no credit card required.'));
});

it('scopes the hosted-plan faq to the free tier when billing is off', function (): void {
    Feature::define(BillingFeature::class, false);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee(__("What's included in the hosted plan?"))
        ->assertDontSee(__('What happens after my trial ends?'))
        ->assertDontSee('2,000-credit');
});
