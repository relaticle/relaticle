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

it('shows the comparison list with the pro-tier hosted price and updates when billing is on', function (): void {
    Feature::define(BillingFeature::class, true);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee(__('Self-hosted or hosted: how to choose'))
        ->assertSee('$19/mo per workspace ($228 billed yearly, or $24/mo billed monthly)')
        ->assertSee(__('Managed by Relaticle — no self-hosted maintenance required'))
        ->assertDontSee(__('$0/mo per workspace'))
        ->assertDontSee(__('Zero-downtime updates and automatic daily backups, handled for you'));
});

it('shows the comparison list with the free-tier hosted price and updates when billing is off', function (): void {
    Feature::define(BillingFeature::class, false);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee(__('Self-hosted or hosted: how to choose'))
        ->assertSee(__('$0/mo per workspace'))
        ->assertSee(__('Zero-downtime updates and automatic daily backups, handled for you'))
        ->assertDontSee('$19/mo per workspace ($228 billed yearly, or $24/mo billed monthly)');
});

it('emits accurate product json-ld offers when billing is on', function (): void {
    Feature::define(BillingFeature::class, true);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee('"name":"Self-hosted","price":"0"', false)
        ->assertSee('"name":"Cloud Pro","price":"19"', false)
        ->assertDontSee('"name":"Cloud","price":"0"', false);
});

it('emits accurate product json-ld offers when billing is off', function (): void {
    Feature::define(BillingFeature::class, false);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee('"name":"Self-hosted","price":"0"', false)
        ->assertSee('"name":"Cloud","price":"0"', false)
        ->assertDontSee('"name":"Cloud Pro"', false);
});

it('discloses the real per-model credit multiplier instead of a flat allowance claim', function (): void {
    $this->get('/pricing')
        ->assertOk()
        ->assertSee(__('What counts as an AI credit?'))
        ->assertSee('3x for Opus 4.7', false)
        ->assertSee('0.5 credits for every tool call')
        ->assertDontSee('One credit is used each time the built-in AI assistant sends a chat reply or generates a record summary.');
});

it('discloses that self-hosted installs are not exempt from the free-tier credit cap', function (): void {
    $this->get('/pricing')
        ->assertOk()
        ->assertSee(__('Are self-hosted installs exempt from AI credit limits?'))
        ->assertSee('300-credit');
});

it('discloses the free-tier credit cap in the billing-off plan-limit answer', function (): void {
    Feature::define(BillingFeature::class, false);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee("Free plan's 300 credits a month")
        ->assertDontSee('CRM data is never capped on any plan — every workspace supports unlimited users, companies, people, opportunities, tasks, and notes, whether you\'re self-hosting or on the hosted Cloud plan.');
});

it('offers a prepaid credit top-up instead of a hard stop in the billing-on plan-limit answer', function (): void {
    Feature::define(BillingFeature::class, true);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee('buy a prepaid credit top-up instead of waiting');
});
