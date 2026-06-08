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

    'deletion' => [
        'grace_period_days' => 30,
        'reminder_days_before' => 5,
    ],

    'features' => [
        'onboard_seed' => (bool) env('RELATICLE_FEATURE_ONBOARD_SEED', true),
        'social_auth' => (bool) env('RELATICLE_FEATURE_SOCIAL_AUTH', true),
        'documentation' => (bool) env('RELATICLE_FEATURE_DOCUMENTATION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Registration access control
    |--------------------------------------------------------------------------
    |
    | When invitation_only is true, a new account can only be created for an
    | email address that has at least one unexpired pending team invitation.
    | This applies to both password registration and social login
    | (Google/GitHub) — an uninvited email cannot bootstrap an account.
    | Existing users are unaffected. Defaults to false so the very first
    | account/team can be created.
    |
    */

    'registration' => [
        'invitation_only' => (bool) env('RELATICLE_REGISTRATION_INVITATION_ONLY', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Team creation access control
    |--------------------------------------------------------------------------
    |
    | When creation_admins_only is true, only a user who owns or is an
    | Administrator of at least one existing team may create new teams.
    | Defaults to false so the first team can be created by anyone.
    |
    */

    'teams' => [
        'creation_admins_only' => (bool) env('RELATICLE_TEAM_CREATION_ADMINS_ONLY', false),
    ],

];
