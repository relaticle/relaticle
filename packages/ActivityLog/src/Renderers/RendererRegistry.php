<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Renderers;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use LogicException;
use Relaticle\ActivityLog\Contracts\TimelineRenderer;
use Relaticle\ActivityLog\Timeline\TimelineEntry;

final class RendererRegistry
{
    /** @var array<string, string|Closure> */
    private array $bindings = [];

    public function __construct(private readonly Container $container) {}

    public function register(string $eventOrType, string|Closure $renderer): void
    {
        $this->bindings[$eventOrType] = $renderer;
    }

    public function unregister(string $eventOrType): void
    {
        unset($this->bindings[$eventOrType]);
    }

    public function resolve(TimelineEntry $entry): TimelineRenderer
    {
        $key = $entry->renderer
            ?? (isset($this->bindings[$entry->event]) ? $entry->event : null)
            ?? (isset($this->bindings[$entry->type]) ? $entry->type : null);

        if ($key === null) {
            /** @var TimelineRenderer */
            return $this->container->make(DefaultRenderer::class);
        }

        return $this->normalize($this->bindings[$key]);
    }

    private function normalize(string|Closure $binding): TimelineRenderer
    {
        if ($binding instanceof Closure) {
            return new readonly class($binding) implements TimelineRenderer
            {
                public function __construct(private Closure $closure) {}

                public function render(TimelineEntry $entry): View|HtmlString
                {
                    /** @var View|HtmlString $result */
                    $result = ($this->closure)($entry);

                    return $result;
                }
            };
        }

        if (class_exists($binding)) {
            $instance = $this->container->make($binding);
            if (! $instance instanceof TimelineRenderer) {
                throw new LogicException(
                    sprintf('%s must implement %s.', $binding, TimelineRenderer::class)
                );
            }

            return $instance;
        }

        return new readonly class($binding, $this->container->make(ViewFactory::class)) implements TimelineRenderer
        {
            public function __construct(private string $view, private ViewFactory $factory) {}

            public function render(TimelineEntry $entry): View|HtmlString
            {
                return $this->factory->make($this->view, ['entry' => $entry]);
            }
        };
    }
}
