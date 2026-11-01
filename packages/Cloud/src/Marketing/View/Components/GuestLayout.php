<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Marketing\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;
use Override;

final class GuestLayout extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?string $ogTitle = null,
        public ?string $ogDescription = null,
        public ?string $ogImage = null,
    ) {}

    /**
     * Get the view / contents that represents the component.
     */
    #[Override]
    public function render(): View
    {
        return view('cloud::layouts.guest');
    }
}
