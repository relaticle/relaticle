<?php

declare(strict_types=1);

use App\Enums\CrmEntity;
use App\Filament\Pages\Workspace\CustomFields;
use App\Support\IconPath;
use Filament\Support\Enums\IconSize;
use Relaticle\Chat\Support\ImageAltTextRenderer;
use Relaticle\Chat\Support\MarkdownRenderer;
use Relaticle\Chat\Support\RecordChipRenderer;
use Relaticle\Chat\Support\RecordReferenceResolver;

mutates(MarkdownRenderer::class, RecordChipRenderer::class, ImageAltTextRenderer::class);

it('renders record reference links as chips', function (): void {
    $html = (new MarkdownRenderer)->render('See [Acme](/r/company/01ABC) today.');

    expect($html)->toContain('class="chat-chip"')
        ->toContain('data-record-type="company"')
        ->toContain('href="/r/company/01ABC"')
        ->toContain('>Acme<');
});

it('leaves ordinary links alone', function (): void {
    $html = (new MarkdownRenderer)->render('[site](https://example.com)');

    // The positive half matters as much as the negative one: without it this
    // would still pass if the renderer swallowed every link and emitted nothing.
    expect($html)->not->toContain('chat-chip')
        ->toContain('<a href="https://example.com">site</a>');
});

it('escapes hostile labels', function (): void {
    $html = (new MarkdownRenderer)->render('[<img src=x onerror=1>](/r/company/01ABC)');

    // Asserting the chip was still built keeps this honest: bailing out of the
    // renderer entirely would otherwise satisfy the "no <img" half on its own.
    expect($html)->toContain('class="chat-chip"')
        ->not->toContain('<img');
});

it('refuses to chip a url carrying an attribute break-out', function (): void {
    $html = (new MarkdownRenderer)->render('[x](</r/company/01" onmouseover=alert(1)>)');

    // The chip regex rejects it (a quote is not in the accepted id charset), so
    // it stays a plain link, and the quote is percent-encoded rather than left
    // free to terminate the href attribute.
    expect($html)->not->toContain('chat-chip')
        ->not->toContain('01" onmouseover')
        ->toContain('<a href="/r/company/01%22%20onmouseover=alert(1)">x</a>');
});

it('flattens a line break inside a chip label', function (string $markdown): void {
    $html = (new MarkdownRenderer)->render($markdown);

    expect($html)->toContain('<span class="chat-chip-label">Acme Corp</span>');
})->with([
    'soft break' => ["[Acme\nCorp](/r/company/01ABC)"],
    'hard break' => ["[Acme  \nCorp](/r/company/01ABC)"],
]);

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

it('shows an image as its alt text and never as an img', function (): void {
    $html = (new MarkdownRenderer)->render('![logo](https://example.com/a.png?d=secret)');

    expect($html)->toContain('logo')
        ->not->toContain('<img')
        ->not->toContain('example.com/a.png');
});

it('renders nothing for an image without alt text', function (): void {
    $html = (new MarkdownRenderer)->render('![](https://example.com/a.png?d=secret)');

    expect(trim(strip_tags($html)))->toBe('')
        ->and($html)->not->toContain('example.com');
});

it('escapes the alt text of an image', function (): void {
    $html = (new MarkdownRenderer)->render('![<b onmouseover=x>"](https://example.com/a.png)');

    expect($html)->not->toContain('<b')
        ->not->toContain('<img')
        ->toContain('&lt;b onmouseover=x&gt;&quot;');
});

it('keeps the words of a formatted alt text and drops its markup', function (): void {
    $html = (new MarkdownRenderer)->render('![the **big** logo](https://example.com/a.png)');

    expect($html)->toContain('the big logo')
        ->not->toContain('<strong>')
        ->not->toContain('<img');
});

it('shows the alt text of an image inside a link and keeps the link', function (): void {
    $html = (new MarkdownRenderer)->render('[![logo](https://example.com/a.png)](https://example.com)');

    expect($html)->toContain('<a href="https://example.com">logo</a>')
        ->not->toContain('<img');
});

it('leaves links and record chips alone next to an image', function (): void {
    $html = (new MarkdownRenderer)->render('![logo](https://example.com/a.png) [site](https://example.com) [Acme](/r/company/01ABC)');

    expect($html)->not->toContain('<img')
        ->toContain('<a href="https://example.com">site</a>')
        ->toContain('class="chat-chip"')
        ->toContain('href="/r/company/01ABC"');
});

it('isolates single-newline-separated block markers into their own paragraphs', function (): void {
    $html = (new MarkdownRenderer)->render("Here is everything:\n{{block:1}}\n{{block:2}}\nEnjoy!");

    expect($html)->toContain('<p>{{block:1}}</p>')
        ->toContain('<p>{{block:2}}</p>')
        ->toContain('<p>Here is everything:</p>')
        ->toContain('<p>Enjoy!</p>');
});

it('leaves an inline block marker untouched', function (): void {
    $html = (new MarkdownRenderer)->render('see {{block:1}} here');

    expect($html)->toContain('<p>see {{block:1}} here</p>');
});

it('degrades a null-url citation to its plain name', function (): void {
    $html = (new MarkdownRenderer)->render('Linked to [Test Person](null).');

    expect($html)->not->toContain('<a')
        ->toContain('Linked to Test Person.');
});

it('wraps markdown tables in a scrollable region', function (): void {
    $html = (new MarkdownRenderer)->render("| a | b |\n|---|---|\n| 1 | 2 |");

    expect($html)->toContain('<div class="chat-md-table overflow-x-auto" tabindex="0" role="region"><table>')
        ->and($html)->toContain('</table></div>');
});

it('draws its chip icons from the shared CRM entity enum, not a private copy', function (): void {
    foreach (CrmEntity::cases() as $case) {
        expect(RecordChipRenderer::iconPath($case->value))->toBe($case->iconPath());
    }

    expect(RecordChipRenderer::iconPath('workspace_invitations'))->toBeNull();
});

it('draws a custom field with the icon its settings page carries in the navigation', function (): void {
    expect(RecordChipRenderer::iconPath('custom_field'))
        ->toBe(IconPath::for(CustomFields::NAVIGATION_ICON->getIconForSize(IconSize::Medium)));
});
