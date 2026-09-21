<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [
    'session' => [
        'driver' => env('SYSADMIN_SESSION_DRIVER', 'database'),
        'connection' => env('SYSADMIN_SESSION_CONNECTION'),
        'store' => null,
        'table' => 'system_administrator_sessions',
        'files' => storage_path('framework/system-administrator-sessions'),
        'prefix' => Str::slug((string) env('APP_NAME', 'laravel'), '_').'_sysadmin_session_',
        'cookie' => Str::slug((string) env('APP_NAME', 'laravel'), '_').'_sysadmin_session',
        'path' => '/',
        'domain' => null,
        'http_only' => true,
        'same_site' => 'strict',
        'lifetime' => (int) env('SYSADMIN_SESSION_LIFETIME', 120),
        'expire_on_close' => (bool) env('SYSADMIN_SESSION_EXPIRE_ON_CLOSE', true),
    ],
];
