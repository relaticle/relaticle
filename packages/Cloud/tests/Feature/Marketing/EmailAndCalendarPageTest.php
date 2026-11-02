<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Features\Billing as BillingFeature;
use App\Features\EmailIntegration;
use Laravel\Pennant\Feature;
use Relaticle\Cloud\CloudServiceProvider;
use Relaticle\Cloud\Marketing\Support\MarketingNavigation;

mutates(CloudServiceProvider::class, MarketingNavigation::class);

it('renders the email and calendar page with its structured data', function (): void {
    Feature::for(null)->activate(EmailIntegration::class);

    $html = $this->get('/email-and-calendar')->assertOk()->getContent();

    expect($html)->toContain(__('Your inbox fills in your CRM.'))
        ->and($html)->toContain(__('Participants only'))
        ->and($html)->toContain('"FAQPage"')
        ->and($html)->toContain('"BreadcrumbList"')
        ->and($html)->toContain(route('emailAndCalendar'));
});

it('quotes the mailbox limit of each plan when billing is on', function (): void {
    Feature::for(null)->activate(EmailIntegration::class);
    Feature::define(BillingFeature::class, true);

    $html = html_entity_decode($this->get('/email-and-calendar')->assertOk()->getContent());

    expect($html)->toContain('The Free plan connects one mailbox, Cloud Pro up to '.Plan::Pro->connectedMailboxes().', and Cloud Scale up to '.Plan::Scale->connectedMailboxes().'.');
});

it('leaves plan limits out when billing is off', function (): void {
    Feature::for(null)->activate(EmailIntegration::class);
    Feature::define(BillingFeature::class, false);

    $html = $this->get('/email-and-calendar')->assertOk()->getContent();

    expect($html)->not->toContain(__('How many mailboxes can I connect?'));
});

it('returns not found when email integration is off', function (): void {
    Feature::for(null)->deactivate(EmailIntegration::class);

    $this->get('/email-and-calendar')->assertNotFound();
});

it('introduces email on the homepage and links the page from the copy and the nav', function (): void {
    Feature::for(null)->activate(EmailIntegration::class);

    $html = $this->get('/')->assertOk()->getContent();
    preg_match('/<header\b.*?<\/header>/s', $html, $header);
    $body = (string) preg_replace(['/<header[\s\S]*?<\/header>/', '/<footer[\s\S]*?<\/footer>/'], '', $html);

    expect($body)->toContain('id="email"')
        ->and($body)->toContain('href="'.route('emailAndCalendar').'"')
        ->and($header[0])->toContain(route('emailAndCalendar'));
});

it('hides the email section and its links when email integration is off', function (): void {
    Feature::for(null)->deactivate(EmailIntegration::class);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('id="email"')
        ->and($html)->not->toContain('/email-and-calendar');
});
