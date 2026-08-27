<?php

declare(strict_types=1);

it('renders the press page with facts and unique metadata', function (): void {
    $this->get('/press')
        ->assertOk()
        ->assertSee('AGPL-3.0')
        ->assertSee('32 MCP tools')
        ->assertSee('<title>'.e(__('Press Kit & Facts')).' - Relaticle</title>', false);
});

it('serves the press page as markdown', function (): void {
    $this->get('/press', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->assertHeader('content-type', 'text/markdown; charset=UTF-8');
});
