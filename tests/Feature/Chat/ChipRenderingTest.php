<?php

declare(strict_types=1);

use Relaticle\Chat\Support\MarkdownRenderer;
use Relaticle\Chat\Support\RecordChipRenderer;
use Relaticle\Chat\Support\RecordReferenceResolver;

mutates(MarkdownRenderer::class, RecordChipRenderer::class);

it('renders record reference links as chips', function (): void {
    $html = (new MarkdownRenderer)->render('See [Acme](/r/company/01ABC) today.');

    expect($html)->toContain('class="chat-chip"')
        ->toContain('data-record-type="company"')
        ->toContain('href="/r/company/01ABC"')
        ->toContain('>Acme<');
});

it('leaves ordinary links alone', function (): void {
    $html = (new MarkdownRenderer)->render('[site](https://example.com)');

    expect($html)->not->toContain('chat-chip');
});

it('escapes hostile labels', function (): void {
    $html = (new MarkdownRenderer)->render('[<img src=x onerror=1>](/r/company/01ABC)');

    expect($html)->not->toContain('<img');
});

it('renders a chip for every citable record type', function (string $type): void {
    $html = (new MarkdownRenderer)->render("[Label](/r/{$type}/01ABC)");

    expect($html)->toContain('class="chat-chip"')
        ->toContain("data-record-type=\"{$type}\"")
        ->toContain('<svg');
})->with(RecordReferenceResolver::CHIP_TYPES);

it('leaves a reference to an unknown record type as a plain link', function (): void {
    $html = (new MarkdownRenderer)->render('[Revenue](/r/custom_field/01ABC)');

    expect($html)->not->toContain('chat-chip')
        ->toContain('<a href="/r/custom_field/01ABC">Revenue</a>');
});

it('keeps inline markup inside a chip label', function (): void {
    $html = (new MarkdownRenderer)->render('[**Acme** Inc](/r/company/01ABC)');

    expect($html)->toContain('<strong>Acme</strong> Inc');
});
