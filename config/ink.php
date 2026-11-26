<?php

declare(strict_types=1);

use App\Http\Middleware\RequireBlogFrontEnd;
use App\Models\User;
use Spatie\MarkdownResponse\Middleware\ProvideMarkdownResponse;

return [
    'prefix' => 'blog',

    'author_model' => User::class,

    'per_page' => 12,

    'features' => [
        // Off unless a package that ships the blog switches them on.
        'public_routes' => false,
        'feed' => true,
        'sitemap' => false,
        'tags' => true,
        'media_library' => false,
        'mcp' => false,
        'url_uploads' => false,
    ],

    /*
     * Ink's own pages need a host layout, so without a configured front end every
     * route behind this list answers 404 instead of rendering a missing view.
     */
    'middleware' => ['web', ProvideMarkdownResponse::class, RequireBlogFrontEnd::class],

    /*
     * The guard ink resolves an MCP caller from. The host that serves the blog MCP
     * server sets it, and nothing serves it over HTTP.
     */
    'mcp' => [
        'guard' => 'sysadmin',
    ],

    'feed' => [
        'author_email' => 'hello@relaticle.com',
    ],

    'publisher' => [
        'name' => 'Relaticle',
        'url' => 'https://relaticle.com',
        // Must resolve to a real, fetchable raster image: Google drops the whole
        // Article rich result when the publisher logo 404s.
        'logo' => 'web-app-manifest-512x512.png',
    ],

    'tables' => [
        'posts' => 'blog_posts',
        'categories' => 'blog_categories',
    ],
];
