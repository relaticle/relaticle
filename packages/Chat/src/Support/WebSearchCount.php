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
     * @return array{name: 'web_search'|'web_fetch', status: 'started'|'completed', query: string|null, url: string|null}|null
     */
    public static function step(ProviderToolEvent $event): ?array
    {
        $status = match ($event->status) {
            'started', 'in_progress' => 'started',
            'completed' => 'completed',
            default => null,
        };
        $kind = self::kindOf($event);

        // OpenAI's search start carries no action yet, so it reads as a search.
        if ($kind === null && $status === 'started' && $event->type === 'web_search_call') {
            $kind = self::SEARCH;
        }

        if ($status === null || $kind === null) {
            return null;
        }

        $input = $event->type === 'server_tool_use' ? ($event->data['input'] ?? []) : ($event->data['action'] ?? []);
        $query = is_array($input) && is_string($input['query'] ?? null) ? mb_substr($input['query'], 0, 200) : null;
        $url = is_array($input) && is_string($input['url'] ?? null) ? WebSources::source(['url' => $input['url']])['url'] ?? null : null;

        return [
            'name' => $kind === self::SEARCH ? 'web_search' : 'web_fetch',
            'status' => $status,
            'query' => $kind === self::SEARCH ? $query : null,
            'url' => $kind === self::FETCH ? $url : null,
        ];
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
