<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class Card extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public ?string $title = null,
        public ?string $header = null,
        public ?string $icon = null,
        public ?string $url = null,
        public string $class = '',
        public string $iconClass = '',
    ) {
        //
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View
    {
        return view('documentation::components.card');
    }
}
