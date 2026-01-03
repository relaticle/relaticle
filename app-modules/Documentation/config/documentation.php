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
        'business' => [
            'title' => 'Business Guide',
            'file' => 'business-guide.md',
            'description' => 'Documentation for business users and stakeholders.',
        ],
        'technical' => [
            'title' => 'Technical Guide',
            'file' => 'technical-guide.md',
            'description' => 'Technical documentation for developers and system administrators.',
        ],
        'quickstart' => [
            'title' => 'Quick Start Guide',
            'file' => 'quick-start-guide.md',
            'description' => 'Get started quickly with essential information.',
        ],
        'import' => [
            'title' => 'Import Guide',
            'file' => 'import-guide.md',
            'description' => 'Complete guide for importing companies, people, opportunities, tasks, and notes into Relaticle.',
        ],
        'api' => [
            'title' => 'API Documentation',
            'file' => 'api-guide.md',
            'description' => 'API reference and integration documentation.',
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
