<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Illuminate\Support\Collection;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\StreamEvent;

final readonly class WebSearchCount
{
    /**
     * @param  Collection<int, StreamEvent>  $events
     */
    public static function in(Collection $events): int
    {
        return $events
            ->whereInstanceOf(ProviderToolEvent::class)
            ->filter(fn (ProviderToolEvent $event): bool => $event->status === 'completed' && self::isSearch($event))
            ->unique(fn (ProviderToolEvent $event): string => $event->itemId)
            ->count();
    }

    private static function isSearch(ProviderToolEvent $event): bool
    {
        return match ($event->type) {
            'server_tool_use' => ($event->data['name'] ?? null) === 'web_search',
            'web_search_call' => ($event->data['action']['type'] ?? null) === 'search',
            default => false,
        };
    }
}
