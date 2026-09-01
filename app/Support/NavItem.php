<?php

declare(strict_types=1);

namespace App\Support;

final readonly class NavItem
{
    /**
     * @param  list<NavItem>  $children
     * @param  string|null  $icon  Name of the `icons.*` component the mega menu renders.
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
