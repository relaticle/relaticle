<?php

declare(strict_types=1);

namespace Relaticle\Chat\Enums;

use Illuminate\Support\Collection;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\StreamEvent;

enum WebTool: string
{
    case Search = 'web_search';
    case Fetch = 'web_fetch';

    public static function of(ProviderToolEvent $event): ?self
    {
        $name = match ($event->type) {
            'server_tool_use' => $event->data['name'] ?? null,
            'web_search_call' => $event->data['action']['type'] ?? null,
            default => null,
        };

        return match ($name) {
            'web_search', 'search' => self::Search,
            'web_fetch', 'open_page', 'find_in_page' => self::Fetch,
            default => null,
        };
    }

    /**
     * @param  Collection<int, StreamEvent>  $events
     */
    public static function anyRanIn(Collection $events): bool
    {
        return self::Search->countIn($events) + self::Fetch->countIn($events) > 0;
    }

    /**
     * @param  Collection<int, StreamEvent>  $events
     */
    public function countIn(Collection $events): int
    {
        return $events
            ->whereInstanceOf(ProviderToolEvent::class)
            ->filter(fn (ProviderToolEvent $event): bool => $event->status === 'completed' && self::of($event) === $this)
            ->unique(fn (ProviderToolEvent $event): string => $event->itemId)
            ->count();
    }
}
