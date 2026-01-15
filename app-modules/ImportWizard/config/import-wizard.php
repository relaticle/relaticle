<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Maximum Rows Per Import
    |--------------------------------------------------------------------------
    |
    | The maximum number of rows allowed per import file. Files exceeding
    | this limit will be rejected with an error message.
    |
    */
    'max_rows_per_file' => 10000,

    /*
    |--------------------------------------------------------------------------
    | Session TTL
    |--------------------------------------------------------------------------
    |
    | How long import session data (cache keys and temp files) should be
    | retained before automatic cleanup.
    |
    */
    'session_ttl_hours' => 24,

    /*
    |--------------------------------------------------------------------------
    | Public Email Domain Filtering
    |--------------------------------------------------------------------------
    |
    | When enabled, automatic company-domain matching will skip common public
    | email providers (gmail.com, outlook.com, yahoo.com, etc.) to prevent
    | false associations. Explicit domain mapping (rel_company_domain) is
    | not affected.
    |
    | Update the domain list with: php artisan import-wizard:update-email-domains
    |
    */
    'public_email_domains' => [
        'enabled' => true,
        'path' => null,
    ],

];
