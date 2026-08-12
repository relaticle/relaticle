<?php

declare(strict_types=1);

it('renders the help hub with category cards', function (): void {
    $this->get('/help')->assertOk()->assertSee('Getting started', false);
});

it('renders a category page', function (): void {
    $this->get('/help/getting-started')->assertOk();
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
