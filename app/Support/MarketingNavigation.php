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
                new NavItem(
                    (string) config('chat.assistant_name'),
                    route('ai'),
                    icon: 'assistant',
                    description: __('The AI teammate that proposes every change'),
                ),
                new NavItem(
                    __('Features'),
                    url('/#features'),
                    icon: 'features',
                    description: __('Records, pipelines, and custom fields'),
                ),
                new NavItem(
                    __('Self-hosted'),
                    route('selfHosted'),
                    icon: 'self-hosted',
                    description: __('Run the whole CRM on your own server'),
                ),
                Feature::active(Documentation::class) ? new NavItem(
                    __('MCP and API'),
                    route('documentation.index'),
                    icon: 'api',
                    description: __('Drive Relaticle from your own tools'),
                ) : null,
            ])),
            new NavItem(__('Resources'), children: $this->groups([
                new NavItem(__('Learn'), children: $this->resourceItems(withDetail: true)),
                new NavItem(__('Compare'), children: $this->comparisonItems()),
            ])),
            new NavItem(__('Pricing'), route('pricing')),
            new NavItem(__('Discord'), route('discord'), external: true),
        ];
    }

    /**
     * Drops groups that feature flags emptied. A childless group has no url
     * either, so every surface would render it as a dead anchor.
     *
     * @param  list<NavItem>  $groups
     * @return list<NavItem>
     */
    private function groups(array $groups): array
    {
        return array_values(array_filter(
            $groups,
            fn (NavItem $group): bool => $group->children !== [],
        ));
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
                new NavItem((string) config('chat.assistant_name'), route('ai')),
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

    /**
     * @param  bool  $withDetail  Adds the icon and one-line description the mega menu renders.
     * @return list<NavItem>
     */
    private function resourceItems(bool $withDetail = false): array
    {
        $detail = fn (string $icon, string $description): array => $withDetail
            ? ['icon' => $icon, 'description' => $description]
            : [];

        return array_values(array_filter([
            Feature::active(Documentation::class) ? new NavItem(
                __('Help center'),
                route('help.index'),
                ...$detail('help', __('Guides for every part of the product')),
            ) : null,
            Feature::active(Documentation::class) ? new NavItem(
                __('Developers'),
                route('documentation.index'),
                ...$detail('developers', __('Self-hosting, MCP, and contributing')),
            ) : null,
            Feature::active(Blog::class) ? new NavItem(
                __('Blog'),
                route('blog.index'),
                ...$detail('blog', __('How we build an open-source CRM')),
            ) : null,
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
