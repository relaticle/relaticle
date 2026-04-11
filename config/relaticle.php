<?php

declare(strict_types=1);

return [

    'contact' => [
        'email' => env('CONTACT_EMAIL', 'hello@relaticle.com'),
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

    'features' => [
        'onboard_seed' => (bool) env('RELATICLE_FEATURE_ONBOARD_SEED', true),
        'social_auth' => (bool) env('RELATICLE_FEATURE_SOCIAL_AUTH', true),
        'documentation' => (bool) env('RELATICLE_FEATURE_DOCUMENTATION', true),
    ],

];
