<?php

declare(strict_types=1);

return [
    /*
     *  You'll find both the API token and endpoint on Mailcoach'
     *  API tokens screen in the Mailcoach settings.
     */
    'api_token' => env('MAILCOACH_API_TOKEN'),

    'endpoint' => env('MAILCOACH_API_ENDPOINT'),

    'enabled_subscribers_sync' => env('MAILCOACH_ENABLED_SUBSCRIBERS_SYNC', false),

    'subscribers_list_id' => env('MAILCOACH_SUBSCRIBERS_LIST_ID'),
];
