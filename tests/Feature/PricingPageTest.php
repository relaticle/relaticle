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

it('does not contradict the credit-multiplier faq with a flat-allowance claim on the billing-on plan card', function (): void {
    Feature::define(BillingFeature::class, true);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee(__('What counts as an AI credit?'))
        ->assertDontSee('1 credit ≈ one AI chat message or record summary')
        ->assertDontSee('All AI models, including premium');
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

it('never names a model the app cannot actually serve', function (): void {
    // Gemini 3 Flash and Gemini 3.1 Pro carry supports_tools => false in chat.php, so
    // ModelDescriptor::isAvailable() always returns false for them and they can never
    // be picked — the page must never claim a plan "unlocks" or is priced for them.
    Feature::define(BillingFeature::class, true);

    $this->get('/pricing')
        ->assertOk()
        ->assertDontSee('Gemini')
        ->assertSee(__('Which AI models does my plan unlock?'))
        ->assertSee('Sonnet 4.6 and self-hosted models')
        ->assertSee('Opus 4.7, GPT 5.5 and GPT 5.4');
});

it('pins the exact membership of every model list derived from the chat catalog', function (): void {
    // $freeCloudModels and $multiplierOneHalfModels (pricing.blade.php) are not covered by
    // any other assertion in this file — a config edit that emptied either one (e.g. Sonnet's
    // min_plan changing, or the GPT rows' credit_multiplier changing) would still pass every
    // other test here, since those only assert substrings that come from the OTHER derived
    // lists ($paidCloudModels / $multiplierOneModels). Each line below anchors one full
    // sentence fragment, so an empty/degraded list breaks the exact assertion for that list.
    Feature::define(BillingFeature::class, true);

    $this->get('/pricing')
        ->assertOk()
        // $freeCloudModels, via $modelsUnlockAnswer
        ->assertSee('Every plan can use Sonnet 4.6 and any self-hosted model you connect yourself.')
        // $paidCloudModels, via $modelsUnlockAnswer
        ->assertSee('Cloud Pro additionally unlocks Opus 4.7, GPT 5.5 and GPT 5.4 — the models with a higher credit multiplier.')
        // $multiplierOneModels, $multiplierOneHalfModels, $multiplierThreeModels, via $creditFaqAnswer
        ->assertSee('1x for Sonnet 4.6 and self-hosted models; 1.5x for GPT 5.5 and GPT 5.4; 3x for Opus 4.7)', false);
});

it('does not claim an unconfirmed Enterprise tier is a purchasable offering when billing is on', function (): void {
    // No Enterprise plan card or checkout path exists in the codebase, so the page must
    // not name it as a contactable/sellable tier anywhere in its visible copy.
    Feature::define(BillingFeature::class, true);

    $this->get('/pricing')
        ->assertOk()
        ->assertDontSee('Enterprise');
});

it('does not claim an unconfirmed Enterprise tier is a purchasable offering when billing is off', function (): void {
    Feature::define(BillingFeature::class, false);

    $this->get('/pricing')
        ->assertOk()
        ->assertDontSee('Enterprise');
});

it('describes the chat rate limit as per workspace, not per person', function (): void {
    $this->get('/pricing')
        ->assertOk()
        ->assertSee(__('Is there a message rate limit?'))
        ->assertSee('shared across the whole workspace, not per person');
});

it('does not overstate the self-hoster\'s lever over the credit cap', function (): void {
    $this->get('/pricing')
        ->assertOk()
        ->assertSee('no plan removes metering entirely')
        ->assertSee("doesn't reset the current period's balance")
        ->assertDontSee('raising or removing that cap is a matter of updating your own workspace\'s plan; there is no separate self-hosted billing UI for it.');
});
