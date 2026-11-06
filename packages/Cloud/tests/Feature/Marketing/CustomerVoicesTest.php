<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Relaticle\Cloud\CloudServiceProvider;

mutates(CloudServiceProvider::class);

describe('Customer voices', function (): void {
    it('renders nothing while the customer config is empty', function (): void {
        config()->set('marketing.testimonials', []);
        config()->set('marketing.logos', []);

        $html = view('cloud::marketing.home.partials.customer-voices')->render();

        expect(trim($html))->toBe('');
    });

    it('renders a named quote and a logo from the customer config', function (): void {
        config()->set('marketing.testimonials', [[
            'quote' => 'We moved our whole pipeline over in an afternoon.',
            'name' => 'Ana Ruiz',
            'role' => 'Founder',
            'company' => 'Northwind Labs',
            'url' => 'https://example.com/ana-ruiz',
            'avatar' => null,
        ]]);
        config()->set('marketing.logos', [[
            'name' => 'Northwind wordmark',
            'src' => 'https://example.com/northwind.svg',
            'url' => null,
        ]]);

        $html = view('cloud::marketing.home.partials.customer-voices')->render();

        expect($html)->toContain('We moved our whole pipeline over in an afternoon.')
            ->and($html)->toContain('Ana Ruiz')
            ->and($html)->toContain('Founder, Northwind Labs')
            ->and($html)->toContain('href="https://example.com/ana-ruiz"')
            ->and($html)->toContain('alt="Northwind wordmark"');
    });
});

it('shows the copy line value as text beside its fallback link', function (): void {
    $html = Blade::render('<x-cloud::marketing.copy-line :value="$value" :href="$href" :href-label="$hrefLabel"/>', [
        'value' => 'https://app.relaticle.com/mcp',
        'href' => 'https://relaticle.com/assistants',
        'hrefLabel' => 'Setup guide',
    ]);

    expect($html)->toContain('>https://app.relaticle.com/mcp</code>')
        ->and($html)->toContain('href="https://relaticle.com/assistants"')
        ->and($html)->toContain('Setup guide');
});
