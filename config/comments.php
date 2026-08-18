<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\TeamMentionResolver;
use Relaticle\Comments\FeatureSystem\FeatureConfigurator;
use Relaticle\Comments\Models\Comment;
use Relaticle\Comments\Policies\CommentPolicy;

return [
    'models' => [
        'comment' => Comment::class,
    ],

    'table_names' => [
        'comments' => 'comments',
        'reactions' => 'comment_reactions',
        'mentions' => 'comment_mentions',
        'subscriptions' => 'comment_subscriptions',
        'attachments' => 'comment_attachments',
    ],

    'column_names' => [
        'commenter_morph' => 'commenter',
    ],

    'commenter' => [
        'model' => User::class,
    ],

    'policy' => CommentPolicy::class,

    'threading' => [
        'max_depth' => 2,
    ],

    'pagination' => [
        'per_page' => 10,
    ],

    'reactions' => [
        'emoji_set' => [
            'thumbs_up' => "\u{1F44D}",
            'heart' => "\u{2764}\u{FE0F}",
            'celebrate' => "\u{1F389}",
            'laugh' => "\u{1F604}",
            'thinking' => "\u{1F914}",
            'sad' => "\u{1F622}",
        ],
    ],

    'mentions' => [
        /*
         | Enable @mention autocomplete in the comment editor and mention
         | notifications. Set to false to turn the feature off entirely.
         */
        'enabled' => true,

        'resolver' => TeamMentionResolver::class,
        'max_results' => 5,

        /*
         | The database column used to display and resolve user names in mentions.
         | Change this if your users table stores names in a different column (e.g. 'username', 'full_name').
         | For computed names (firstname + lastname), leave this as 'name' and use
         | CommentsConfig::resolveUserNameUsing() in your AppServiceProvider instead.
         */
        'name_column' => 'name',

        /*
         | Columns to search when looking up users during @mention autocomplete.
         | Useful when the display name is composed of multiple columns (e.g. ['firstname', 'lastname']).
         | Defaults to ['name'] (or whatever name_column is set to) when not specified.
         */
        // 'search_columns' => ['firstname', 'lastname'],
    ],

    'pinning' => [
        'enabled' => true,

        /*
         | Maximum number of pinned comments per commentable.
         | Set to null for unlimited. When the limit is reached, pinning is silently blocked.
         */
        'max_pinned' => 3,
    ],

    'editor' => [
        'toolbar' => [
            ['bold', 'italic', 'strike', 'link'],
            ['bulletList', 'orderedList'],
            ['codeBlock'],
        ],
    ],

    'notifications' => [
        'channels' => ['database'],
        'enabled' => true,
    ],

    'subscriptions' => [
        'auto_subscribe' => true,
    ],

    'attachments' => [
        'enabled' => true,
        'disk' => 'public',
        'max_size' => 10240,
        'allowed_types' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',
            'text/plain',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
    ],

    'broadcasting' => [
        'enabled' => false,
        'channel_prefix' => 'comments',
    ],

    'polling' => [
        'interval' => '10s',
    ],

    /*
     | Enable or disable optional package features.
     | Reserved for future features — add cases from CommentsFeature enum here.
     */
    'features' => FeatureConfigurator::configure(),

    'multi_tenancy' => [
        'enabled' => true,

        /*
         | The column name that stores the tenant identifier on every comments table.
         | Change this if your application uses a different column (e.g. team_id, org_id).
         */
        'tenant_column' => 'team_id',

        /*
         | The database column type for the tenant identifier.
         | Use 'unsignedBigInteger' for integer IDs (default),
         | 'uuid' for UUID tenant keys, or 'string' for any other string-based key.
         */
        'tenant_column_type' => 'string',

        /*
         | A callable that returns the current tenant's primary key (int|string|null).
         | Register it in a service provider:
         |
         |   CommentsConfig::resolveTenantUsing(fn () => Filament::getTenant()?->getKey());
         |
         | When null (or when the callable returns null), the scope is skipped entirely.
         | This is intentional: CLI commands and queue workers run without an active tenant.
         */
        'tenant_resolver' => null,
    ],
];
