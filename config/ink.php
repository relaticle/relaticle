<?php

declare(strict_types=1);

use App\Models\User;
use Spatie\MarkdownResponse\Middleware\ProvideMarkdownResponse;

return [
    'prefix' => 'blog',

    'author_model' => User::class,

    'per_page' => 12,

    'features' => [
        // Overwritten in AppServiceProvider::register() from the Pennant Blog flag,
        // which runs before ink registers its routes. Off here so a missed override
        // fails closed rather than publishing the blog.
        'public_routes' => false,
        'feed' => true,
        'sitemap' => false,
        'tags' => true,
        'media_library' => false,
        'mcp' => true,
    ],

    /*
     * Our own marketing views render through ink's controllers, so we get the
     * package's listing SEO, search and pagination without duplicating it. These
     * are app views, not published copies of ink's — nothing to drift.
     */
    'views' => [
        'index' => 'blog.index',
        'show' => 'blog.show',
        'category' => 'blog.index',
        'tag' => 'blog.index',
        'preview' => 'blog.preview',
        'feed' => 'blog.feed',
    ],

    'middleware' => ['web', ProvideMarkdownResponse::class],

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
