<?php

declare(strict_types=1);

it('resolves http scheme when BROADCAST_REVERB_HOST is 127.0.0.1', function (): void {
    putenv('BROADCAST_REVERB_HOST=127.0.0.1');
    putenv('BROADCAST_REVERB_PORT=8080');
    putenv('REVERB_HOST=relaticle.test');
    putenv('REVERB_SCHEME=https');

    try {
        $config = require config_path('broadcasting.php');

        $reverb = $config['connections']['reverb'];

        expect($reverb['options']['host'])->toBe('127.0.0.1')
            ->and($reverb['options']['port'])->toBe(8080)
            ->and($reverb['options']['scheme'])->toBe('http')
            ->and($reverb['options']['useTLS'])->toBeFalse()
            ->and($reverb['client_options']['verify'])->toBeFalse();
    } finally {
        putenv('BROADCAST_REVERB_HOST');
        putenv('BROADCAST_REVERB_PORT');
        putenv('REVERB_HOST');
        putenv('REVERB_SCHEME');
    }
});

it('resolves http scheme when BROADCAST_REVERB_HOST is localhost', function (): void {
    putenv('BROADCAST_REVERB_HOST=localhost');
    putenv('REVERB_SCHEME=https');

    try {
        $config = require config_path('broadcasting.php');

        expect($config['connections']['reverb']['options']['scheme'])->toBe('http');
    } finally {
        putenv('BROADCAST_REVERB_HOST');
        putenv('REVERB_SCHEME');
    }
});

it('keeps https scheme for non-loopback broadcast hosts', function (): void {
    putenv('BROADCAST_REVERB_HOST=reverb.example.com');
    putenv('REVERB_SCHEME=https');

    try {
        $config = require config_path('broadcasting.php');

        expect($config['connections']['reverb']['options']['scheme'])->toBe('https')
            ->and($config['connections']['reverb']['options']['useTLS'])->toBeTrue();
    } finally {
        putenv('BROADCAST_REVERB_HOST');
        putenv('REVERB_SCHEME');
    }
});

it('honors an explicit BROADCAST_REVERB_SCHEME override', function (): void {
    putenv('BROADCAST_REVERB_HOST=127.0.0.1');
    putenv('BROADCAST_REVERB_SCHEME=https');

    try {
        $config = require config_path('broadcasting.php');

        expect($config['connections']['reverb']['options']['scheme'])->toBe('https');
    } finally {
        putenv('BROADCAST_REVERB_HOST');
        putenv('BROADCAST_REVERB_SCHEME');
    }
});

it('falls back to the app key for the Reverb secret', function (): void {
    putenv('REVERB_APP_ID=');
    putenv('REVERB_APP_KEY=');
    putenv('REVERB_APP_SECRET=');

    try {
        $reverb = (require config_path('broadcasting.php'))['connections']['reverb'];

        expect($reverb['key'])->toBe('relaticle')
            ->and($reverb['app_id'])->toBe('relaticle')
            ->and($reverb['secret'])->toBe(config('app.key'));
    } finally {
        putenv('REVERB_APP_ID');
        putenv('REVERB_APP_KEY');
        putenv('REVERB_APP_SECRET');
    }
});

it('keeps explicit Reverb credentials', function (): void {
    putenv('REVERB_APP_ID=1001');
    putenv('REVERB_APP_KEY=explicit-key');
    putenv('REVERB_APP_SECRET=explicit-secret');

    try {
        $reverb = (require config_path('broadcasting.php'))['connections']['reverb'];

        expect($reverb['key'])->toBe('explicit-key')
            ->and($reverb['app_id'])->toBe('1001')
            ->and($reverb['secret'])->toBe('explicit-secret');
    } finally {
        putenv('REVERB_APP_ID');
        putenv('REVERB_APP_KEY');
        putenv('REVERB_APP_SECRET');
    }
});

/**
 * @param  array<string, string>  $env
 * @return array<string, mixed>
 */
function echoConfigFor(string $appUrl, array $env): array
{
    $previous = array_intersect_key($_SERVER, $env);
    $_SERVER = [...$_SERVER, ...$env];
    config()->set('broadcasting.default', 'reverb');
    config()->set('app.url', $appUrl);

    try {
        return (require config_path('filament.php'))['broadcasting']['echo'] ?? [];
    } finally {
        $_SERVER = [...array_diff_key($_SERVER, $env), ...$previous];
    }
}

it('serves the websocket from the app origin under /reverb when no Reverb host is set', function (): void {
    $echo = echoConfigFor('https://crm.example.com', ['REVERB_HOST' => '']);

    expect($echo)->toMatchArray([
        'wsHost' => 'crm.example.com',
        'wsPort' => 443,
        'wssPort' => 443,
        'wsPath' => '/reverb',
        'forceTLS' => true,
    ]);
});

it('keeps the port of a plain http app origin', function (): void {
    $echo = echoConfigFor('http://192.168.1.20:8080', ['REVERB_HOST' => '']);

    expect($echo)->toMatchArray([
        'wsHost' => '192.168.1.20',
        'wsPort' => 8080,
        'wsPath' => '/reverb',
        'forceTLS' => false,
    ]);
});

it('points Echo at a public Reverb host when one is set', function (): void {
    $echo = echoConfigFor('https://crm.example.com', [
        'REVERB_HOST' => 'ws.example.com',
        'REVERB_PORT' => '8443',
        'REVERB_SCHEME' => 'https',
    ]);

    expect($echo)->toMatchArray([
        'wsHost' => 'ws.example.com',
        'wsPort' => 8443,
        'wsPath' => '',
        'forceTLS' => true,
    ]);
});

it('hands the panel no Echo config when broadcasting is not reverb', function (): void {
    config()->set('broadcasting.default', 'log');

    expect((require config_path('filament.php'))['broadcasting'])->toBe([]);
});
