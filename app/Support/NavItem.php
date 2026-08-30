<?php

declare(strict_types=1);

namespace App\Support;

final readonly class NavItem
{
    /**
     * Icon names resolved through their own `icons.*` component (isocons.app
     * set). Anything else falls back to the legacy name-keyed `x-brand.nav-icon`.
     *
     * @var list<string>
     */
    public const array ICON_COMPONENTS = ['rela', 'features', 'self-hosted', 'api', 'help', 'developers'];

    /**
     * @param  list<NavItem>  $children
     * @param  string|null  $icon  Icon name resolved by the mega menu: either an `icons.*`
     *                             component (isocons.app set) or, as a fallback, a name
     *                             from `x-brand.nav-icon`.
     * @param  string|null  $description  One line shown under the label in the mega menu.
     */
    public function __construct(
        public string $label,
        public ?string $url = null,
        public bool $external = false,
        public array $children = [],
        public ?string $icon = null,
        public ?string $description = null,
    ) {}
}
