<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Content Path
    |--------------------------------------------------------------------------
    |
    | Root directory for the markdown-driven content engine (DocsRepository).
    | Every file lives at {content_path}/{area}/{category}/{slug}.md, e.g.
    | help/getting-started/create-your-first-company.md. A category's own
    | metadata lives in that directory's _index.md.
    |
    */
    'content_path' => base_path('packages/Documentation/resources/content'),

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | These options control the caching behavior of the documentation package.
    |
    | `enabled` also gates DocsRepository's content manifest (alongside
    | app.debug, which always bypasses it) — flip DOCUMENTATION_CACHE_ENABLED
    | off to force a rebuild without touching a file. `ttl` does not apply to
    | DocsRepository: its manifest cache is invalidated by comparing a
    | content-hash signature on every read, not by expiry, so a stale entry
    | can't outlive the content it was built from.
    |
    */
    'cache' => [
        'enabled' => env('DOCUMENTATION_CACHE_ENABLED', true),
        'ttl' => env('DOCUMENTATION_CACHE_TTL', 3600), // In seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Settings
    |--------------------------------------------------------------------------
    |
    | Controls search functionality behavior and constraints.
    |
    */
    'search' => [
        'enabled' => true,
        'min_length' => 3,
        'highlight' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Documentation Types
    |--------------------------------------------------------------------------
    |
    | Defines the types of documentation available in the system.
    |
    */
    'documents' => [
        'getting-started' => [
            'title' => 'Getting Started',
            'file' => 'getting-started.md',
            'description' => 'Set up your account and learn the basics.',
        ],
        'import' => [
            'title' => 'Import Guide',
            'file' => 'import-guide.md',
            'description' => 'Import data from CSV files.',
        ],
        'developer' => [
            'title' => 'Developer Guide',
            'file' => 'developer-guide.md',
            'description' => 'Installation, architecture, and contributing.',
        ],
        'self-hosting' => [
            'title' => 'Self-Hosting Guide',
            'file' => 'self-hosting-guide.md',
            'description' => 'Deploy Relaticle with Docker or manually.',
        ],
        'mcp' => [
            'title' => 'MCP Server',
            'file' => 'mcp-guide.md',
            'description' => 'Connect AI assistants like Claude to your CRM.',
        ],
        'api' => [
            'title' => 'API Reference',
            'url' => '/docs/api',
            'description' => 'REST API documentation for managing CRM entities.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Markdown Processing
    |--------------------------------------------------------------------------
    |
    | Settings for markdown processing and rendering.
    |
    */
    'markdown' => [
        'allow_html' => false,
        'code_highlighting' => true,
        'table_of_contents' => true,
        'base_path' => base_path('packages/Documentation/resources/markdown'),
    ],
];
