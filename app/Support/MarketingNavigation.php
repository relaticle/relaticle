<?php

declare(strict_types=1);

namespace App\Support;

use App\Features\Blog;
use App\Features\Documentation;
use Laravel\Pennant\Feature;

final readonly class MarketingNavigation
{
    /** @return list<NavItem> */
    public function header(): array
    {
        return array_values(array_filter([
            new NavItem(__('Features'), url('/#features')),
            new NavItem(__('Pricing'), route('pricing')),
            Feature::active(Documentation::class) ? new NavItem(__('Help'), route('help.index')) : null,
            Feature::active(Documentation::class) ? new NavItem(__('Developers'), route('documentation.index')) : null,
            Feature::active(Blog::class) ? new NavItem(__('Blog'), route('blog.index')) : null,
            new NavItem(__('Discord'), route('discord'), external: true),
        ]));
    }

    /** @return list<NavItem> */
    public function mobile(): array
    {
        return $this->header();
    }

    /** @return list<NavItem> Columns: label + children links. */
    public function footer(): array
    {
        $facts = CompetitorFacts::all();

        $compare = array_map(
            fn (string $slug): NavItem => new NavItem(
                __('Relaticle vs :name', ['name' => $facts[$slug]['name']]),
                route('compare.show', ['competitor' => $slug]),
            ),
            config('comparisons.compare', []),
        );

        $alternatives = array_map(
            fn (string $slug): NavItem => new NavItem(
                __(':name alternative', ['name' => $facts[$slug]['name']]),
                route('alternatives.show', ['competitor' => $slug]),
            ),
            config('comparisons.alternatives', []),
        );

        return [
            new NavItem(__('Product'), children: [
                new NavItem(__('Features'), url('/#features')),
                new NavItem(__('Pricing'), route('pricing')),
            ]),
            new NavItem(__('Resources'), children: array_values(array_filter([
                Feature::active(Documentation::class) ? new NavItem(__('Help center'), route('help.index')) : null,
                Feature::active(Documentation::class) ? new NavItem(__('Developers'), route('documentation.index')) : null,
                Feature::active(Blog::class) ? new NavItem(__('Blog'), route('blog.index')) : null,
                new NavItem(__('Press kit'), route('press')),
            ]))),
            new NavItem(__('Compare'), children: array_values([...$compare, ...$alternatives])),
            new NavItem(__('Company'), children: [
                new NavItem(__('Contact'), route('contact')),
                new NavItem(__('GitHub'), 'https://github.com/relaticle/relaticle', external: true),
                new NavItem(__('Privacy Policy'), url('privacy-policy')),
                new NavItem(__('Terms of Service'), url('terms-of-service')),
            ]),
        ];
    }
}
