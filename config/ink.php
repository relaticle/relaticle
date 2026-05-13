<?php

declare(strict_types=1);
use App\Models\User;

return [
    'prefix' => 'blog',

    'author_model' => User::class,

    'per_page' => 12,

    'features' => [
        'public_routes' => false,
        'feed' => false,
        'sitemap' => false,
        'tags' => true,
        'media_library' => false,
    ],

    'feed' => [
        'title' => 'Relaticle Engineering Blog',
        'description' => 'Deep dives into building an open-source CRM for AI agents.',
        'author_email' => 'hello@relaticle.com',
    ],

    'publisher' => [
        'name' => 'Relaticle',
        'url' => 'https://relaticle.com',
        'logo' => 'images/logo.png',
    ],

    'tables' => [
        'posts' => 'blog_posts',
        'categories' => 'blog_categories',
    ],
];
