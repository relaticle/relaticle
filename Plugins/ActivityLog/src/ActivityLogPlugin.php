<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Relaticle\ActivityLog\Renderers\RendererRegistry;

final class ActivityLogPlugin implements Plugin
{
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
     * @param  array<string, string|Closure>  $renderers
     */
    public function renderers(array $renderers): static
    {
        $this->renderers = $renderers;

        return $this;
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void
    {
        $registry = resolve(RendererRegistry::class);

        foreach ($this->renderers as $key => $renderer) {
            $registry->register($key, $renderer);
        }
    }
}
