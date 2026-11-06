<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Marketing\Support;

use App\Features\Blog;
use App\Features\Documentation;
use App\Features\EmailIntegration;
use Illuminate\Support\Arr;
use Laravel\Pennant\Feature;
use Relaticle\Cloud\Marketing\Enums\ConnectableAssistant;
use Relaticle\EmailIntegration\Enums\EmailProvider;

final readonly class MarketingNavigation
{
    /** @return list<NavItem> */
    public function header(): array
    {
        return [
            new NavItem(__('Product'), children: array_values(array_filter([
                new NavItem(
                    (string) config('chat.assistant_name'),
                    route('ai'),
                    icon: 'rela',
                    description: __('The AI teammate that proposes every change'),
                ),
                new NavItem(
                    __('Features'),
                    url('/#features'),
                    icon: 'features',
                    description: __('Records, pipelines, and custom fields'),
                ),
                Feature::active(EmailIntegration::class) ? new NavItem(
                    __('Email and calendar'),
                    route('emailAndCalendar'),
                    icon: 'email',
                    description: __(':providers on every record', ['providers' => $this->mailProviderNames()]),
                ) : null,
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
            ]))),
            new NavItem(__('Resources'), children: $this->groups([
                new NavItem(__('Learn'), children: $this->resourceItems(withDetail: true)),
                new NavItem(__('Compare'), children: $this->comparisonItems()),
            ])),
            new NavItem(__('Pricing'), route('pricing')),
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
            new NavItem(__('Product'), children: array_values(array_filter([
                new NavItem(__(':name AI assistant', ['name' => (string) config('chat.assistant_name')]), route('ai')),
                new NavItem(__('Features'), url('/#features')),
                Feature::active(EmailIntegration::class) ? new NavItem(__('Email and calendar'), route('emailAndCalendar')) : null,
                new NavItem(__('Pricing'), route('pricing')),
                new NavItem(__('Self-hosted'), route('selfHosted')),
                ...array_map(
                    fn (ConnectableAssistant $assistant): NavItem => new NavItem(
                        __('For :name', ['name' => $assistant->label()]),
                        route($assistant->routeName()),
                    ),
                    ConnectableAssistant::cases(),
                ),
            ]))),
            new NavItem(__('Resources'), children: [
                ...$this->resourceItems(),
                new NavItem(__('AI-native CRM'), route('aiNativeCrm')),
            ]),
            new NavItem(__('Compare'), children: $this->comparisonItems()),
            new NavItem(__('Company'), children: [
                new NavItem(__('Contact'), route('contact')),
                new NavItem(__('Security'), route('security')),
                new NavItem(__('Press kit'), route('press')),
            ]),
        ];
    }

    private function mailProviderNames(): string
    {
        $configured = array_filter(EmailProvider::cases(), fn (EmailProvider $provider): bool => $provider->isConfigured());

        return Arr::join(array_map(fn (EmailProvider $provider): string => match ($provider) {
            EmailProvider::GMAIL => 'Gmail',
            EmailProvider::AZURE => 'Outlook',
        }, $configured === [] ? [EmailProvider::GMAIL] : $configured), ', ', ' and ');
    }

    /** @return list<NavItem> */
    public function legal(): array
    {
        return [
            new NavItem(__('Privacy Policy'), url('privacy-policy')),
            new NavItem(__('Terms of Service'), url('terms-of-service')),
        ];
    }

    // A near spelling wins; otherwise the section index, so a removed post suggests `/blog`.
    public function closestTo(string $path): ?NavItem
    {
        $path = '/'.mb_strtolower(trim($path, '/'));
        $section = '/'.strtok($path, '/');
        $closest = null;
        $closestDistance = PHP_INT_MAX;

        foreach ([...array_merge(...array_map(fn (NavItem $column): array => $column->children, $this->footer())), ...$this->legal()] as $item) {
            $candidate = parse_url((string) $item->url, PHP_URL_PATH) ?: '/';

            if ($item->external || $candidate === '/' || $candidate === $path) {
                continue;
            }

            $distance = levenshtein($path, $candidate);
            $isNear = $distance <= max(1, min(2, intdiv(mb_strlen($path), 4)));

            if (($isNear || $candidate === $section) && $distance < $closestDistance) {
                $closest = $item;
                $closestDistance = $distance;
            }
        }

        return $closest;
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
