<?php

declare(strict_types=1);

return [

    'contact' => [
        'email' => env('CONTACT_EMAIL', 'hello@relaticle.com'),
    ],

    'company' => [
        'name' => env('RELATICLE_COMPANY_NAME', 'Relaticle'),
        'address' => env('RELATICLE_COMPANY_ADDRESS', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Toggle Relaticle features on or off. Useful for forks and custom
    | deployments that want to disable specific functionality without
    | modifying upstream code. All features are enabled by default.
    |
    */

    'deletion' => [
        'grace_period_days' => 30,
        'reminder_days_before' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Horizon Access
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of email addresses allowed to open the Horizon
    | dashboard outside the local environment. Empty denies everyone, so a
    | deployment that never sets this exposes nothing.
    |
    */

    'horizon' => [
        'admin_emails' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('HORIZON_ADMIN_EMAILS', '')),
        ))),
    ],

    'features' => [
        'onboard_seed' => (bool) env('RELATICLE_FEATURE_ONBOARD_SEED', true),
        'social_auth' => (bool) env('RELATICLE_FEATURE_SOCIAL_AUTH', true),
        'documentation' => (bool) env('RELATICLE_FEATURE_DOCUMENTATION', true),
        'billing' => (bool) env('RELATICLE_FEATURE_BILLING', false),
        'support_menu' => (bool) env('RELATICLE_FEATURE_SUPPORT_MENU', false),
    ],

];
