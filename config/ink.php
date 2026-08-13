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

    /*
     * ink's Mcp::web() route already carries ReorderJsonAccept and
     * AddWwwAuthenticateHeader. auth:sanctum-sysadmin authenticates the caller
     * against the dedicated guard scoped to system_administrators
     * (config/auth.php) -- the app's own /mcp and /api/v1/* routes use the
     * default 'sanctum' guard, scoped to users only, so a blog token and an
     * app token are never accepted on each other's endpoints. throttle:mcp
     * (defined in AppServiceProvider::configureRateLimiting(), shared with the
     * app's own /mcp endpoint) bounds request volume once a caller has a token.
     *
     * WARNING: never add 'web' to this route's middleware. Sanctum's
     * Guard::__invoke() checks the 'web' session guard before it ever consults
     * the bearer token / configured provider, so a caller with an unrelated
     * logged-in web session would silently authenticate as that session user
     * instead of the sanctum-sysadmin token holder.
     */
    'mcp' => [
        'path' => '/mcp/blog',
        'guard' => 'sanctum-sysadmin',
        'middleware' => ['auth:sanctum-sysadmin', 'throttle:mcp'],
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
