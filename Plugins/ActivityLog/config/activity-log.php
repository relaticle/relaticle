<?php

declare(strict_types=1);

return [
    'default_per_page' => 20,
    'pagination_buffer' => 2,
    'deduplicate_by_default' => true,

    'source_priorities' => [
        'activity_log' => 10,
        'related_activity_log' => 10,
        'related_model' => 20,
        'custom' => 30,
    ],

    'date_groups' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'older'],

    'renderers' => [
        // 'email_sent' => \App\Timeline\Renderers\EmailSentRenderer::class,
    ],

    'cache' => [
        'store' => null,
        'ttl_seconds' => 0,
        'key_prefix' => 'activity-log',
    ],
];
