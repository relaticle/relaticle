<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Renderers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Relaticle\ActivityLog\Contracts\TimelineRenderer;
use Relaticle\ActivityLog\Timeline\TimelineEntry;

final class ActivityLogRenderer implements TimelineRenderer
{
    public function render(TimelineEntry $entry): View|HtmlString
    {
        return view('activity-log::entries.activity-log', ['entry' => $entry]);
    }
}
