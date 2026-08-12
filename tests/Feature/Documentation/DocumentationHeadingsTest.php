<?php

declare(strict_types=1);

it('renders documentation headings without a literal permalink symbol or mangled id', function (): void {
    $html = $this->get('/docs/mcp')->assertOk()->getContent();

    preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $html, $h1);

    expect(trim(strip_tags($h1[1])))->toBe('MCP Server')
        ->and($html)->not->toMatch('/<h1[^>]*id="a-id/')
        ->and($html)->not->toContain('>#</a>');
});
