<?php

declare(strict_types=1);
use App\Models\User;

return [
    'prefix' => 'blog',
    'author_model' => User::class,
    'per_page' => 12,
    'feed' => [
        'enabled' => true,
        'title' => 'Relaticle Engineering Blog',
        'description' => 'Deep dives into building an open-source CRM for AI agents.',
        'author_email' => 'hello@relaticle.com',
    ],
    'publisher' => [
        'name' => 'Relaticle',
        'url' => 'https://relaticle.com',
        'logo' => 'images/logo.png',
    ],
];
