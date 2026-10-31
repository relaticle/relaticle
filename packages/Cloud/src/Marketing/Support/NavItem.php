<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Marketing\Support;

final readonly class NavItem
{
    /** @param  list<NavItem>  $children */
    public function __construct(
        public string $label,
        public ?string $url = null,
        public bool $external = false,
        public array $children = [],
        public ?string $icon = null,
        public ?string $description = null,
    ) {}
}
