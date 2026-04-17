<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Renderers;

final class RendererRegistry
{
    /** @var array<string, mixed> */
    private array $bindings = [];

    public function register(string $eventOrType, mixed $renderer): void
    {
        $this->bindings[$eventOrType] = $renderer;
    }

    public function get(string $eventOrType): mixed
    {
        return $this->bindings[$eventOrType] ?? null;
    }
}
