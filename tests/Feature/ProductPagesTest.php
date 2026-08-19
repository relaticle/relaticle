<?php

declare(strict_types=1);

it('renders the Rela page with verified capability claims', function (): void {
    $html = $this->get('/ai')->assertOk()->getContent();

    expect($html)->toContain(config('chat.assistant_name'))
        ->and($html)->toContain(__('Nothing writes without your approval'))
        ->and($html)->toContain(__('MCP server'))
        ->and($html)->toContain('"FAQPage"')
        ->and($html)->toContain('"BreadcrumbList"');
});

it('does not claim hosted models ship with self-hosted installs', function (): void {
    $html = $this->get('/ai')->assertOk()->getContent();

    expect($html)->toContain(__('bring your own API key'));
});

it('describes batch approval as per record, matching how the service resolves batches', function (): void {
    $html = $this->get('/ai')->assertOk()->getContent();

    expect($html)->toContain(__('Batches are reviewed record by record'))
        ->and($html)->not->toContain('all-or-nothing')
        ->and($html)->not->toContain('no partial approval');
});

it('names the assistant from config rather than a hardcoded literal', function (): void {
    config()->set('chat.assistant_name', 'Testbot');

    $this->get('/ai')->assertOk()->assertSee('Testbot');
});

it('renders the animated demo at the #demo anchor the hero CTA points to', function (): void {
    $html = $this->get('/ai')->assertOk()->getContent();

    expect($html)->toContain('id="demo"')
        ->and($html)->toContain('hero-chat-animate')
        ->and($html)->toContain('hero-agent-preview')
        ->and($html)->toContain('IntersectionObserver');
});
