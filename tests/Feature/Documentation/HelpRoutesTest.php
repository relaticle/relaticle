<?php

declare(strict_types=1);

use Relaticle\Documentation\Support\DocsNavigation;

mutates(DocsNavigation::class);

it('renders the help hub with category cards', function (): void {
    $this->get('/help')->assertOk()->assertSee('Getting started', false);
});

it('renders a category page', function (): void {
    $this->get('/help/getting-started')
        ->assertOk()
        ->assertSee('Getting started', false)
        ->assertSee('Create your first company', false);
});

it('renders an article with its rendered body', function (): void {
    $this->get('/help/getting-started/create-your-first-company')
        ->assertOk()
        ->assertSee('Create your first company', false);
});

it('404s an unknown category and an unknown article', function (): void {
    $this->get('/help/no-such-category')->assertNotFound();
    $this->get('/help/getting-started/no-such-article')->assertNotFound();
});

it('serves an article as markdown to an agent', function (): void {
    $markdown = $this->get('/help/getting-started/create-your-first-company', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->getContent();

    expect($markdown)->toContain('Create your first company')
        ->and($markdown)->not->toContain('Start for free');
});

it('strips the related-articles nav from the markdown response but keeps the body', function (): void {
    $markdown = $this->get('/help/getting-started/create-your-first-company', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->getContent();

    expect($markdown)->toContain('A company record tracks an account')
        ->and($markdown)->not->toContain('Add your first person');
});

it('renders documentation pages in their own shell, not the marketing chrome', function (string $path): void {
    $html = $this->get($path)->assertOk()->getContent();

    // The marketing header component is the thing the docs surface must not
    // mount -- its id is the only stable marker of that specific chrome.
    expect($html)->not->toContain('id="main-header"')
        ->and($html)->toContain('Search the docs');
})->with(['/help', '/help/getting-started', '/help/getting-started/create-your-first-company', '/developers', '/developers/mcp']);

it('offers both areas in the sidebar of every documentation page', function (string $path): void {
    $html = $this->get($path)->assertOk()->getContent();

    expect($html)->toContain(route('help.show', ['category' => 'getting-started', 'slug' => 'create-your-first-company']))
        ->and($html)->toContain(route('documentation.show', ['type' => 'mcp']));
})->with(['/help', '/help/getting-started', '/help/getting-started/create-your-first-company', '/developers', '/developers/mcp']);

it('serves the .md variant the article copy-page action fetches', function (): void {
    $this->get('/help/getting-started/create-your-first-company.md')
        ->assertOk()
        ->assertHeader('content-type', 'text/markdown; charset=UTF-8');
});

it('keeps the shell chrome out of the markdown variant', function (): void {
    $markdown = $this->get('/help/getting-started/create-your-first-company', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->getContent();

    // Search dialog, off-canvas nav and skip link are browser-only affordances:
    // an agent asking for markdown has no use for any of them.
    expect($markdown)->not->toContain('Searching help and developer docs')
        ->and($markdown)->not->toContain('Browse the docs')
        ->and($markdown)->not->toContain('Skip to content')
        ->and($markdown)->toContain('A company record tracks an account');
});

it('links to help and developers from the marketing header, mobile nav, and footer', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    // A future nav refactor that silently drops one of these anchors still
    // passes an "assertSee" style check -- count the occurrences instead.
    expect(substr_count($html, route('help.index')))->toBeGreaterThanOrEqual(3)
        ->and(substr_count($html, route('documentation.index')))->toBeGreaterThanOrEqual(3);
});
