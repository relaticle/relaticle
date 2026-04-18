<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Contracts;

use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Relaticle\ActivityLog\Timeline\TimelineEntry;

interface TimelineRenderer
{
    public function render(TimelineEntry $entry): View|HtmlString;
}
