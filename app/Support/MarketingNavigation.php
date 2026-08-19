<?php

declare(strict_types=1);

namespace App\Support;

use App\Features\Blog;
use App\Features\Documentation;
use Laravel\Pennant\Feature;

final readonly class MarketingNavigation
{
    private const string GITHUB_URL = 'https://github.com/relaticle/relaticle';

    /** @return list<NavItem> */
    public function header(): array
    {
        return [
            new NavItem(__('Product'), children: array_filter([
                new NavItem(__('Rela'), route('ai')),
                new NavItem(__('Features'), url('/#features')),
                new NavItem(__('Self-hosted'), route('selfHosted')),
                Feature::active(Documentation::class) ? new NavItem(__('MCP and API'), route('documentation.index')) : null,
            ])),
            new NavItem(__('Resources'), children: [
                new NavItem(__('Resources'), children: $this->resourceItems()),
                new NavItem(__('Compare'), children: $this->comparisonItems()),
            ]),
            new NavItem(__('Pricing'), route('pricing')),
            new NavItem(__('Discord'), route('discord'), external: true),
        ];
    }

    /** @return list<NavItem> */
    public function mobile(): array
    {
        return $this->header();
    }

    /** @return list<NavItem> Columns: label + children links. */
    public function footer(): array
    {
        return [
            new NavItem(__('Product'), children: [
                new NavItem(__('Rela'), route('ai')),
                new NavItem(__('Features'), url('/#features')),
                new NavItem(__('Pricing'), route('pricing')),
                new NavItem(__('Self-hosted'), route('selfHosted')),
            ]),
            new NavItem(__('Resources'), children: [
                ...$this->resourceItems(),
                new NavItem(__('Press kit'), route('press')),
            ]),
            new NavItem(__('Compare'), children: $this->comparisonItems()),
            new NavItem(__('Company'), children: [
                new NavItem(__('Contact'), route('contact')),
                new NavItem(__('GitHub'), self::GITHUB_URL, external: true),
                new NavItem(__('Privacy Policy'), url('privacy-policy')),
                new NavItem(__('Terms of Service'), url('terms-of-service')),
            ]),
        ];
    }

    /** @return list<NavItem> */
    private function resourceItems(): array
    {
        return array_values(array_filter([
            Feature::active(Documentation::class) ? new NavItem(__('Help center'), route('help.index')) : null,
            Feature::active(Documentation::class) ? new NavItem(__('Developers'), route('documentation.index')) : null,
            Feature::active(Blog::class) ? new NavItem(__('Blog'), route('blog.index')) : null,
        ]));
    }

    /** @return list<NavItem> */
    private function comparisonItems(): array
    {
        return [...$this->compareItems(), ...$this->alternativeItems()];
    }

    /** @return list<NavItem> */
    private function compareItems(): array
    {
        $facts = CompetitorFacts::all();

        return array_values(array_map(
            fn (string $slug): NavItem => new NavItem(
                __('Relaticle vs :name', ['name' => $facts[$slug]['name']]),
                route('compare.show', ['competitor' => $slug]),
            ),
            config('comparisons.compare', []),
        ));
    }

    /** @return list<NavItem> */
    private function alternativeItems(): array
    {
        $facts = CompetitorFacts::all();

        return array_values(array_map(
            fn (string $slug): NavItem => new NavItem(
                __(':name alternative', ['name' => $facts[$slug]['name']]),
                route('alternatives.show', ['competitor' => $slug]),
            ),
            config('comparisons.alternatives', []),
        ));
    }
}
