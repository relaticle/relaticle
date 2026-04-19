<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Filament;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Relaticle\ActivityLog\Renderers\RendererRegistry;

final class ActivityLogPlugin implements Plugin
{
    /** @var array<string, string|Closure> */
    private array $renderers = [];

    public static function make(): self
    {
        return app(self::class);
    }

    public function getId(): string
    {
        return 'activity-log';
    }

    /**
     * @param  array<string, string|Closure>  $renderers
     */
    public function renderers(array $renderers): self
    {
        $this->renderers = [...$this->renderers, ...$renderers];

        return $this;
    }

    public function register(Panel $panel): void
    {
        $registry = app(RendererRegistry::class);

        foreach ($this->renderers as $eventOrType => $renderer) {
            $registry->register($eventOrType, $renderer);
        }
    }

    public function boot(Panel $panel): void {}
}
