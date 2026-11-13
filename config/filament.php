<?php

declare(strict_types=1);

$appUrl = parse_url((string) config('app.url'));
$reverbHost = env('REVERB_HOST');
$reverbIsSecure = $reverbHost ? env('REVERB_SCHEME', 'https') === 'https' : ($appUrl['scheme'] ?? 'http') === 'https';
$reverbPort = (int) ($reverbHost ? env('REVERB_PORT', 443) : $appUrl['port'] ?? ($reverbIsSecure ? 443 : 80));

return [

    /*
    |--------------------------------------------------------------------------
    | Broadcasting
    |--------------------------------------------------------------------------
    |
    | Filament renders this into every panel page and builds Echo from it, so
    | the browser reads the websocket address at runtime and one built bundle
    | serves any host. The chat streams over the same Echo instance.
    |
    | With no REVERB_HOST, the socket is served from the APP_URL origin under
    | /reverb, where the web server proxies it to Reverb.
    |
    */

    'broadcasting' => config('broadcasting.default') === 'reverb' ? [

        'echo' => [
            'broadcaster' => 'reverb',
            'key' => config('broadcasting.connections.reverb.key'),
            'wsHost' => $reverbHost ?: ($appUrl['host'] ?? 'localhost'),
            'wsPort' => $reverbPort,
            'wssPort' => $reverbPort,
            'wsPath' => $reverbHost ? '' : '/reverb',
            'forceTLS' => $reverbIsSecure,
            'enabledTransports' => ['ws', 'wss'],
        ],

    ] : [],

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | This is the storage disk Filament will use to store files. You may use
    | any of the disks defined in the `config/filesystems.php`.
    |
    */

    'default_filesystem_disk' => env('FILAMENT_FILESYSTEM_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Assets Path
    |--------------------------------------------------------------------------
    |
    | This is the directory where Filament's assets will be published to. It
    | is relative to the `public` directory of your Laravel application.
    |
    | After changing the path, you should run `php artisan filament:assets`.
    |
    */

    'assets_path' => null,

    /*
    |--------------------------------------------------------------------------
    | Cache Path
    |--------------------------------------------------------------------------
    |
    | This is the directory that Filament will use to store cache files that
    | are used to optimize the registration of components.
    |
    | After changing the path, you should run `php artisan filament:cache-components`.
    |
    */

    'cache_path' => base_path('bootstrap/cache/filament'),

    /*
    |--------------------------------------------------------------------------
    | Livewire Loading Delay
    |--------------------------------------------------------------------------
    |
    | This sets the delay before loading indicators appear.
    |
    | Setting this to 'none' makes indicators appear immediately, which can be
    | desirable for high-latency connections. Setting it to 'default' applies
    | Livewire's standard 200ms delay.
    |
    */

    'livewire_loading_delay' => 'default',

];
