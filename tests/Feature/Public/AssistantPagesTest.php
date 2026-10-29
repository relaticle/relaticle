<?php

declare(strict_types=1);

use App\Features\Billing as BillingFeature;
use Illuminate\Support\Js;
use Laravel\Pennant\Feature;

function assistantPageText(string $html): string
{
    $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
    $text = strip_tags($text);

    return trim(preg_replace('/\s+/', ' ', html_entity_decode($text)) ?? $text);
}

it('renders an assistant page with its structured data', function (string $assistant, string $name): void {
    $html = $this->get("/crm-for-{$assistant}")->assertOk()->getContent();

    expect($html)->toContain(__('The CRM you run from :name', ['name' => $name]))
        ->and($html)->toContain(__('Connect :name in three steps', ['name' => $name]))
        ->and($html)->toContain('"FAQPage"')
        ->and($html)->toContain('"BreadcrumbList"')
        ->and($html)->toContain('<link rel="canonical" href="'.route("assistants.{$assistant}").'"');
})->with([
    ['claude', 'Claude'],
    ['chatgpt', 'ChatGPT'],
]);

it('gives Claude the MCP server address to paste as a custom connector', function (): void {
    $this->get('/crm-for-claude')->assertOk()->assertSee(url()->getMcpUrl());
});

it('offers a copy control for the MCP server address on the Claude page only', function (): void {
    $claude = $this->get('/crm-for-claude')->assertOk()->getContent();
    $chatGpt = $this->get('/crm-for-chatgpt')->assertOk()->getContent();

    expect($claude)->toContain('navigator.clipboard.writeText('.Js::from(url()->getMcpUrl()).')')
        ->and($chatGpt)->not->toContain('navigator.clipboard.writeText');
});

it('shows an example exchange built from the default pipeline stages', function (string $assistant): void {
    $text = assistantPageText($this->get("/crm-for-{$assistant}")->assertOk()->getContent());

    expect($text)->toContain('An example exchange.')
        ->and($text)->toContain('Proposal/Price Quote')
        ->and($text)->toContain('Negotiation/Review');
})->with(['claude', 'chatgpt']);

it('names each assistant in llms.txt with its own summary', function (): void {
    $body = $this->get('/llms.txt')->assertOk()->getContent();

    expect($body)->toContain('- [Relaticle for Claude]('.route('assistants.claude').'): Connect Claude to the CRM with OAuth')
        ->and($body)->toContain('- [Relaticle for ChatGPT]('.route('assistants.chatgpt').'): Install the Relaticle plugin in ChatGPT');
});

it('sends ChatGPT users to the plugin and tells them to mention it', function (): void {
    $html = $this->get('/crm-for-chatgpt')->assertOk()->getContent();

    expect($html)->toContain('https://chatgpt.com/plugins/')
        ->and(assistantPageText($html))->toContain('Start a message with @Relaticle');
});

it('says connector changes apply directly and scopes the approval step to the built-in assistant', function (string $assistant): void {
    $text = assistantPageText($this->get("/crm-for-{$assistant}")->assertOk()->getContent());

    expect($text)->toContain('A connected assistant writes straight to your records')
        ->and($text)->toContain(config('chat.assistant_name').', the built-in assistant, asks before every change instead.')
        ->and($text)->toContain('Changes made through the connector apply directly');
})->with(['claude', 'chatgpt']);

it('links each assistant page to the other, the setup guide and pricing from its own copy', function (): void {
    $html = $this->get('/crm-for-claude')->assertOk()->getContent();

    $body = (string) preg_replace(['/<header[\s\S]*?<\/header>/', '/<footer[\s\S]*?<\/footer>/'], '', $html);

    expect($body)->toContain('href="'.route('assistants.chatgpt').'"')
        ->and($body)->toContain('href="'.route('help.show', ['category' => 'ai-assistant', 'slug' => 'connect-claude-or-chatgpt']).'"')
        ->and($body)->toContain('href="'.route('pricing').'"');
});

it('says the workspace needs Cloud Pro after the trial rather than calling the connector free', function (): void {
    withHostedBilling();

    $text = assistantPageText($this->get('/crm-for-chatgpt')->assertOk()->getContent());

    expect($text)->toContain('the workspace needs a Cloud Pro subscription to stay active')
        ->and($text)->not->toContain('The connector is free');
});

it('has no copy holes from empty interpolations', function (string $assistant): void {
    $html = $this->get("/crm-for-{$assistant}")->assertOk()->getContent();

    expect($html)
        ->not->toMatch('/\s-day Cloud/')
        ->not->toContain(':name')
        ->not->toContain(':count')
        ->not->toContain(':days')
        ->not->toContain(':assistant');
})->with(['claude', 'chatgpt']);

it('is linked from the AI-native CRM page copy so a reader can reach both pages', function (): void {
    $html = $this->get('/ai-native-crm')->assertOk()->getContent();

    $body = (string) preg_replace(['/<header[\s\S]*?<\/header>/', '/<footer[\s\S]*?<\/footer>/'], '', $html);

    expect($body)->toContain('href="'.route('assistants.claude').'"')
        ->and($body)->toContain('href="'.route('assistants.chatgpt').'"');
});

it('names the Cloud Pro trial in the cost answer when billing is on', function (): void {
    withHostedBilling();

    expect($this->get('/crm-for-claude')->assertOk()->getContent())->toContain('14-day Cloud Pro trial');
});

it('leaves the trial out of the cost answer when billing is off', function (): void {
    Feature::define(BillingFeature::class, false);

    expect($this->get('/crm-for-claude')->assertOk()->getContent())->not->toContain('Cloud Pro trial');
});

it('names the built-in assistant from config rather than a hardcoded literal', function (): void {
    config()->set('chat.assistant_name', 'Testbot');

    $this->get('/crm-for-chatgpt')->assertOk()->assertSee('Testbot');
});
