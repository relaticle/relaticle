<?php

declare(strict_types=1);

/**
 * Dated, sourced facts about Relaticle and its CRM competitors.
 *
 * Single source of truth for every public competitor claim (press page,
 * comparison pages). Every field that carries a number or price has its own
 * `*_verified` date, and the whole entry has a `verified` date — both are
 * read by `php artisan gtm:stale-facts` to flag claims older than 90 days.
 *
 * `stars` is 0 for closed-source products with no public repository — that
 * is the verified fact, not a placeholder.
 *
 * @return array<string, array{
 *     name: string,
 *     license: string,
 *     stars: int,
 *     stars_verified: string,
 *     pricing: string,
 *     pricing_verified: string,
 *     stack: string,
 *     self_host: string,
 *     ai: string,
 *     verified: string,
 * }>
 */
return [
    'relaticle' => [
        'name' => 'Relaticle',
        'license' => 'AGPL-3.0',
        'stars' => 1_506,
        'stars_verified' => '2026-08-13',
        'pricing' => '$24/mo flat (or $19/mo billed annually), unlimited users, AI credit packs on top',
        'pricing_verified' => '2026-08-13',
        'stack' => 'Laravel 13 + Filament 5, single-server deploy',
        'self_host' => 'Self-host free under AGPL-3.0, no feature gating',
        'ai' => '32 first-party MCP tools plus a built-in AI chat assistant; MCP, chat, and Ollama all work self-hosted',
        'verified' => '2026-08-13',
    ],
    'twenty' => [
        'name' => 'Twenty',
        'license' => 'AGPL-3.0 + Twenty Application Exception (enterprise files license-tagged in-repo)',
        'stars' => 54_877,
        'stars_verified' => '2026-08-13',
        'pricing' => 'Pro $9/user/mo, Organization $19/user/mo (billed yearly), Enterprise from $50k/yr',
        'pricing_verified' => '2026-08-13',
        'stack' => 'Node/NestJS + Redis + Postgres + background workers',
        'self_host' => 'Self-hostable core; enterprise-tagged files are license-restricted',
        'ai' => 'First-party MCP server marketed for Cloud workspaces',
        'verified' => '2026-08-13',
    ],
    'espocrm' => [
        'name' => 'EspoCRM',
        'license' => 'AGPL-3.0',
        'stars' => 3_226,
        'stars_verified' => '2026-08-13',
        'pricing' => 'Cloud Basic $15/user/mo (min 3 users), Enterprise $25/user/mo (min 5), Ultimate $69/user/mo (min 10)',
        'pricing_verified' => '2026-08-13',
        'stack' => 'PHP',
        'self_host' => 'Free self-hosted core; paid extensions for self-hosters',
        'ai' => 'No first-party AI or MCP tooling',
        'verified' => '2026-08-13',
    ],
    'attio' => [
        'name' => 'Attio',
        'license' => 'Closed-source SaaS',
        'stars' => 0,
        'stars_verified' => '2026-08-13',
        'pricing' => 'Free (up to 3 seats), Plus $35/user/mo, Pro $79/user/mo (billed yearly; $44/$99 monthly), Enterprise custom — see attio.com/pricing',
        'pricing_verified' => '2026-08-13',
        'stack' => 'Closed-source SaaS, proprietary stack',
        'self_host' => 'No self-hosting option',
        'ai' => 'Proprietary AI research and enrichment features',
        'verified' => '2026-08-13',
    ],
    'hubspot' => [
        'name' => 'HubSpot',
        'license' => 'Closed-source SaaS',
        'stars' => 0,
        'stars_verified' => '2026-08-13',
        'pricing' => 'Free CRM (up to 2 users), paid Hubs from $20/seat/mo (Starter) — see hubspot.com/pricing',
        'pricing_verified' => '2026-08-13',
        'stack' => 'Closed-source SaaS, proprietary stack',
        'self_host' => 'No self-hosting option',
        'ai' => 'Proprietary AI features bundled into paid Hubs',
        'verified' => '2026-08-13',
    ],
];
