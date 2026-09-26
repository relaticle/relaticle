<?php

declare(strict_types=1);

namespace App\ActivityLog;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Relaticle\ActivityLog\Contracts\TimelineRenderer;
use Relaticle\ActivityLog\Timeline\TimelineEntry;

final readonly class AppEventRenderer implements TimelineRenderer
{
    public function __construct(private ViewFactory $viewFactory) {}

    public function render(TimelineEntry $entry): View|HtmlString
    {
        $palette = AppEventPalette::from($entry->event);

        return $this->viewFactory->make('activity-log.entries.app-event', [
            'entry' => $entry,
            'palette' => $palette,
        ]);
    }
}
