<?php

declare(strict_types=1);

namespace Relaticle\OnboardSeed\Support;

use Illuminate\Database\Eloquent\Model;

final class FixtureRegistry
{
    /**
     * Registry of entities
     *
     * @var array<string, array<string, Model>>
     */
    private static array $registry = [];

    /**
     * Register an entity in the registry
     *
     * @param  string  $type  The entity type (e.g., 'companies', 'people')
     * @param  string  $key  The entity key
     * @param  Model  $entity  The entity model
     */
    public static function register(string $type, string $key, Model $entity): void
    {
        if (! isset(self::$registry[$type])) {
            self::$registry[$type] = [];
        }

        self::$registry[$type][$key] = $entity;
    }

    /**
     * Get an entity from the registry
     *
     * @param  string  $type  The entity type
     * @param  string  $key  The entity key
     * @return Model|null The entity or null if not found
     */
    public static function get(string $type, string $key): ?Model
    {
        return self::$registry[$type][$key] ?? null;
    }

    public static function clear(): void
    {
        self::$registry = [];
    }
}
