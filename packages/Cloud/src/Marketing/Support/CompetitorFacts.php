<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Marketing\Support;

/**
 * Single source of truth for public competitor claims (press page, comparison pages).
 *
 * Backed by `packages/Cloud/resources/data/competitor-facts.php` so the facts stay a plain,
 * diffable data file rather than code. See `php artisan gtm:stale-facts` for
 * the companion staleness report.
 */
final readonly class CompetitorFacts
{
    /**
     * @return array<string, array{
     *     name: string,
     *     license: string,
     *     stars: int,
     *     stars_verified: string,
     *     contributors: int|string,
     *     contributors_verified: string,
     *     pricing: string,
     *     pricing_verified: string,
     *     stack: string,
     *     self_host: string,
     *     ai: string,
     *     extensibility: string,
     *     source_urls: array{website: string, pricing: string, repository?: string, mcp?: string, apps?: string, exports?: string, backup?: string},
     *     verified: string,
     * }>
     */
    public static function all(): array
    {
        /**
         * @var array<string, array{
         *     name: string,
         *     license: string,
         *     stars: int,
         *     stars_verified: string,
         *     contributors: int|string,
         *     contributors_verified: string,
         *     pricing: string,
         *     pricing_verified: string,
         *     stack: string,
         *     self_host: string,
         *     ai: string,
         *     extensibility: string,
         *     source_urls: array{website: string, pricing: string, repository?: string, mcp?: string, apps?: string, exports?: string, backup?: string},
         *     verified: string,
         * }> $facts
         */
        $facts = require dirname(__DIR__, 3).'/resources/data/competitor-facts.php';

        return $facts;
    }
}
