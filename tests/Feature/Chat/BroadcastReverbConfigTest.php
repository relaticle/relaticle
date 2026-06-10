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
