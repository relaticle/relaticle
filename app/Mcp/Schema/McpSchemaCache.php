<?php

declare(strict_types=1);

namespace App\Mcp\Schema;

use App\Enums\CrmEntity;
use Illuminate\Support\Facades\Cache;

/**
 * Owns both per-tenant MCP schema cache keys.
 *
 * Nothing else may spell these keys. The TTL is long enough that a missed
 * invalidation reads to the agent as "that field doesn't exist" right after the
 * user created it, and a key rebuilt from a literal in a second class is exactly
 * how that invalidation goes quietly missing.
 */
final readonly class McpSchemaCache
{
    public const int TTL = 60;

    public static function entitySchemaKey(int|string $tenantId, string $entityType): string
    {
        return "custom_fields_schema_{$tenantId}_{$entityType}";
    }

    public static function filterSchemaKey(int|string $tenantId, string $entityType): string
    {
        return "custom_fields_filter_schema_{$tenantId}_{$entityType}";
    }

    public static function forget(int|string $tenantId, string $entityType): void
    {
        Cache::forget(self::entitySchemaKey($tenantId, $entityType));
        Cache::forget(self::filterSchemaKey($tenantId, $entityType));
    }

    /**
     * Drop every entity's schema for one tenant, for callers that know the tenant
     * but would need a query to learn the entity type.
     */
    public static function forgetTenant(int|string $tenantId): void
    {
        foreach (CrmEntity::morphAliases() as $entityType) {
            self::forget($tenantId, $entityType);
        }
    }
}
