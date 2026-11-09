<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Vite;

final readonly class ContentSecurityPolicy
{
    private const array ALWAYS_ENFORCED = [
        "frame-ancestors 'none'",
        "object-src 'none'",
        "base-uri 'self'",
    ];

    private const string FATHOM = 'https://cdn.usefathom.com';

    private const string BUNNY_FONTS = 'https://fonts.bunny.net';

    private const string SCALAR_CDN = 'https://cdn.jsdelivr.net';

    private const string SCALAR_FONTS = 'https://fonts.scalar.com';

    private const string TURNSTILE = 'https://challenges.cloudflare.com';

    public static function alwaysEnforced(): string
    {
        return implode('; ', self::ALWAYS_ENFORCED);
    }

    public static function full(bool $apiDocs = false, bool $horizon = false): string
    {
        $directives = [];

        foreach (self::sources($apiDocs, $horizon) as $directive => $sources) {
            $directives[] = $directive.' '.implode(' ', array_unique($sources));
        }

        return implode('; ', [...$directives, ...self::ALWAYS_ENFORCED]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function sources(bool $apiDocs, bool $horizon): array
    {
        $storage = self::storageOrigins();
        $assets = self::assetOrigins();

        $sources = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'", self::FATHOM, self::TURNSTILE, ...$assets],
            'style-src' => ["'self'", "'unsafe-inline'", ...$assets],
            // Email bodies render in a srcdoc frame that inherits this policy, and their images are remote.
            'img-src' => ["'self'", 'data:', 'blob:', 'https:', ...$assets, ...$storage],
            'font-src' => ["'self'", 'data:', ...$assets],
            'connect-src' => ["'self'", self::FATHOM, ...self::reverbOrigins(), ...$storage],
            'media-src' => ["'self'", 'blob:', ...$storage],
            'frame-src' => ["'self'", self::TURNSTILE],
        ];

        foreach (self::viteDevOrigins() as $origin) {
            foreach (['script-src', 'style-src', 'font-src', 'img-src', 'connect-src'] as $directive) {
                $sources[$directive][] = $origin;
            }
        }

        if ($apiDocs) {
            $sources['script-src'][] = self::SCALAR_CDN;
            $sources['font-src'][] = self::SCALAR_FONTS;
            array_push($sources['connect-src'], ...self::foreignOrigins([
                config('scribe.try_it_out.base_url'),
                config('scribe.base_url'),
            ]));
        }

        if ($horizon) {
            $sources['style-src'][] = self::BUNNY_FONTS;
            $sources['font-src'][] = self::BUNNY_FONTS;
        }

        foreach ((array) config('app.csp.extra') as $directive => $extra) {
            $sources[$directive] = [...$sources[$directive], ...$extra];
        }

        return $sources;
    }

    /**
     * @return array<int, string>
     */
    private static function assetOrigins(): array
    {
        return self::foreignOrigins([config('app.asset_url')]);
    }

    /**
     * @return array<int, string>
     */
    private static function storageOrigins(): array
    {
        $urls = [config('filesystems.disks.public.url')];

        foreach (config()->array('filesystems.disks') as $disk) {
            if (is_array($disk) && ($disk['driver'] ?? null) === 's3' && filled($disk['bucket'] ?? null)) {
                $urls[] = $disk['url'] ?? null;
                $urls[] = $disk['temporary_url'] ?? null;
                array_push($urls, ...self::presignedUrls($disk));
            }
        }

        return self::foreignOrigins($urls);
    }

    /**
     * @param  array<string, mixed>  $disk
     * @return array<int, string>
     */
    private static function presignedUrls(array $disk): array
    {
        $bucket = (string) $disk['bucket'];
        $endpoint = (string) ($disk['endpoint'] ?? '');
        $pathStyle = (bool) ($disk['use_path_style_endpoint'] ?? false);

        if ($endpoint !== '') {
            return [$pathStyle ? $endpoint : (string) preg_replace('#^(https?://)#', "$1{$bucket}.", $endpoint)];
        }

        $region = (string) ($disk['region'] ?? '');

        if ($region === '') {
            return [];
        }

        // The AWS SDK signs us-east-1 on the legacy global host unless regional endpoints are configured.
        $hosts = $region === 'us-east-1' ? ['s3.amazonaws.com', 's3.us-east-1.amazonaws.com'] : ["s3.{$region}.amazonaws.com"];

        return array_map(
            fn (string $host): string => $pathStyle ? "https://{$host}" : "https://{$bucket}.{$host}",
            $hosts,
        );
    }

    /**
     * @param  array<int, mixed>  $urls
     * @return array<int, string>
     */
    private static function foreignOrigins(array $urls): array
    {
        $appOrigin = self::origin(config()->string('app.url'));
        $origins = [];

        foreach ($urls as $url) {
            $origin = is_string($url) ? self::origin($url) : null;

            if ($origin !== null && $origin !== $appOrigin) {
                $origins[] = $origin;
            }
        }

        return array_values(array_unique($origins));
    }

    private static function origin(string $url): ?string
    {
        $parts = $url === '' ? false : parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return "{$parts['scheme']}://{$parts['host']}".(isset($parts['port']) ? ":{$parts['port']}" : '');
    }

    /**
     * @return array<int, string>
     */
    private static function reverbOrigins(): array
    {
        $echo = config('filament.broadcasting.echo');

        if (! is_array($echo)) {
            return [];
        }

        $scheme = $echo['forceTLS'] ? 'wss' : 'ws';

        return ["{$scheme}://{$echo['wsHost']}:{$echo['wsPort']}"];
    }

    /**
     * @return array<int, string>
     */
    private static function viteDevOrigins(): array
    {
        if (! Vite::isRunningHot()) {
            return [];
        }

        $origin = rtrim(trim((string) file_get_contents(Vite::hotFile())), '/');
        $websocket = preg_replace('/^http/', 'ws', $origin);

        return array_filter([$origin, $websocket]);
    }
}
