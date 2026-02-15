<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | These options control the caching behavior of the documentation module.
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
        'api' => [
            'title' => 'API Reference',
            'file' => 'api-guide.md',
            'description' => 'REST API documentation (coming soon).',
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
        'base_path' => base_path('app-modules/Documentation/resources/markdown'),
    ],
];
