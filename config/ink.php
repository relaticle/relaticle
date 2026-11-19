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
    ],

    /*
     * Ink's own pages need a host layout, so without a configured front end every
     * route behind this list answers 404 instead of rendering a missing view.
     */
    'middleware' => ['web', ProvideMarkdownResponse::class, RequireBlogFrontEnd::class],

    /*
     * The blog MCP server is not served over HTTP. `php artisan blog:mcp` serves it over
     * stdio and resolves the caller from this guard.
     */
    'mcp' => [
        'guard' => 'sysadmin',
    ],

    'feed' => [
        'title' => 'Relaticle Engineering Blog',
        'description' => 'Deep dives into building an open-source CRM for AI agents.',
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
