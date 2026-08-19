<?php

declare(strict_types=1);

it('renders the Rela page with verified capability claims', function (): void {
    $html = $this->get('/ai')->assertOk()->getContent();

    expect($html)->toContain(config('chat.assistant_name'))
        ->and($html)->toContain(__('Nothing writes without your approval'))
        ->and($html)->toContain(__('MCP'))
        ->and($html)->toContain('"FAQPage"')
        ->and($html)->toContain('"BreadcrumbList"');
});

it('does not claim hosted models ship with self-hosted installs', function (): void {
    $html = $this->get('/ai')->assertOk()->getContent();

    expect($html)->toContain(__('bring your own API key'));
});
