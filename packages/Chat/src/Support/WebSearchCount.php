<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Illuminate\Support\Collection;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\StreamEvent;

final readonly class WebSearchCount
{
    private const string SEARCH = 'search';

    private const string FETCH = 'fetch';

    /**
     * @param  Collection<int, StreamEvent>  $events
     */
    public static function in(Collection $events): int
    {
        return self::distinct($events, self::SEARCH);
    }

    /**
     * @param  Collection<int, StreamEvent>  $events
     */
    public static function fetchesIn(Collection $events): int
    {
        return self::distinct($events, self::FETCH);
    }

    /**
     * @param  Collection<int, StreamEvent>  $events
     */
    private static function distinct(Collection $events, string $kind): int
    {
        return $events
            ->whereInstanceOf(ProviderToolEvent::class)
            ->filter(fn (ProviderToolEvent $event): bool => $event->status === 'completed' && self::kindOf($event) === $kind)
            ->unique(fn (ProviderToolEvent $event): string => $event->itemId)
            ->count();
    }

    private static function kindOf(ProviderToolEvent $event): ?string
    {
        $name = match ($event->type) {
            'server_tool_use' => $event->data['name'] ?? null,
            'web_search_call' => $event->data['action']['type'] ?? null,
            default => null,
        };

        return match ($name) {
            'web_search', 'search' => self::SEARCH,
            'web_fetch', 'open_page' => self::FETCH,
            default => null,
        };
    }
}
