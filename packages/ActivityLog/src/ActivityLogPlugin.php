<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Relaticle\ActivityLog\Renderers\RendererRegistry;

final class ActivityLogPlugin implements Plugin
{
    /** @var array<class-string<Widget>|WidgetConfiguration> */
    private array $widgets = [];

    /** @var array<string, string|Closure> */
    private array $renderers = [];

    public static function make(): static
    {
        return resolve(self::class);
    }

    public function getId(): string
    {
        return 'activity-log';
    }

    /**
     * @param  array<class-string<Widget>|WidgetConfiguration>  $widgets
     */
    public function widgets(array $widgets): static
    {
        $this->widgets = $widgets;

        return $this;
    }

    /**
     * @param  array<string, string|Closure>  $renderers
     */
    public function renderers(array $renderers): static
    {
        $this->renderers = $renderers;

        return $this;
    }

    public function register(Panel $panel): void
    {
        if ($this->widgets !== []) {
            $panel->widgets($this->widgets);
        }
    }

    public function boot(Panel $panel): void
    {
        $registry = resolve(RendererRegistry::class);

        foreach ($this->renderers as $key => $renderer) {
            $registry->register($key, $renderer);
        }
    }
}
