<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use Relaticle\ActivityLog\Renderers\RendererRegistry;

/**
 * @method static void registerRenderer(string $eventOrType, string|Closure $renderer)
 * @method static void unregisterRenderer(string $eventOrType)
 */
final class Timeline extends Facade
{
    public static function registerRenderer(string $eventOrType, string|Closure $renderer): void
    {
        app(RendererRegistry::class)->register($eventOrType, $renderer);
    }

    public static function unregisterRenderer(string $eventOrType): void
    {
        app(RendererRegistry::class)->unregister($eventOrType);
    }

    protected static function getFacadeAccessor(): string
    {
        return RendererRegistry::class;
    }
}
