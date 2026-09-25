<?php

declare(strict_types=1);

use App\Features\Billing as BillingFeature;
use App\Support\CompetitorFacts;
use Laravel\Pennant\Feature;

function aiNativeCrmPageText(string $html): string
{
    $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
    $text = strip_tags($text);

    return trim(preg_replace('/\s+/', ' ', html_entity_decode($text)) ?? $text);
}

it('renders the AI-native CRM page with its structured data', function (): void {
    $html = $this->get('/ai-native-crm')->assertOk()->getContent();

    expect($html)->toContain(__('The AI-native CRM you can run on your own server'))
        ->and($html)->toContain(__('Five checks before you call a CRM AI-native'))
        ->and($html)->toContain('"FAQPage"')
        ->and($html)->toContain('"BreadcrumbList"')
        ->and($html)->toContain(route('aiNativeCrm'));
});

it('reads the MCP tool count from the competitor facts rather than a literal', function (): void {
    $html = $this->get('/ai-native-crm')->assertOk()->getContent();

    expect($html)->toContain(__('A first-party MCP server with :count tools and a REST API.', ['count' => CompetitorFacts::mcpToolCount()]));
});

it('scopes the approval promise to the assistant and says MCP writes commit directly', function (): void {
    $text = aiNativeCrmPageText($this->get('/ai-native-crm')->assertOk()->getContent());

    expect($text)->toContain('Agents connected over MCP write directly')
        ->and($text)->toContain(config('chat.assistant_name').' proposes every change as a card and waits.')
        ->and($text)->not->toMatch('/MCP[^.]*approv|approv[^.]*MCP/i');
});

it('links to the assistant page, self-hosting, and the MCP docs', function (): void {
    $html = $this->get('/ai-native-crm')->assertOk()->getContent();

    expect($html)->toContain(route('ai'))
        ->and($html)->toContain(route('selfHosted'))
        ->and($html)->toContain(route('documentation.show', ['type' => 'mcp']));
});

it('is linked from the assistant page so the sitemap crawler can find it', function (): void {
    $this->get('/ai')->assertOk()->assertSee(route('aiNativeCrm'), false);
});

it('describes the Cloud Pro trial when billing is on', function (): void {
    Feature::define(BillingFeature::class, true);

    $html = $this->get('/ai-native-crm')->assertOk()->getContent();

    expect($html)->toContain('14-day Cloud Pro trial')
        ->and($html)->toContain('2,000-credit monthly allowance');
});

it('describes the free allowance when billing is off', function (): void {
    Feature::define(BillingFeature::class, false);

    $html = $this->get('/ai-native-crm')->assertOk()->getContent();

    expect($html)->toContain('300-credit monthly allowance')
        ->and($html)->not->toContain('Cloud Pro trial');
});

it('names the assistant from config rather than a hardcoded literal', function (): void {
    config()->set('chat.assistant_name', 'Testbot');

    $this->get('/ai-native-crm')->assertOk()->assertSee('Testbot');
});

it('has no copy holes from empty interpolations', function (): void {
    $html = $this->get('/ai-native-crm')->assertOk()->getContent();

    expect($html)
        ->not->toMatch('/with\s+tools/')
        ->not->toMatch('/\s-day Cloud/')
        ->not->toMatch('/\s-credit/')
        ->not->toContain(':name')
        ->not->toContain(':count');
});
