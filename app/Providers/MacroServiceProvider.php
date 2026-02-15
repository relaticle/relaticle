<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

final class MacroServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        URL::macro('getAppUrl', function (string $path = ''): string {
            if ($domain = config('app.app_panel_domain')) {
                $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?? 'https';

                return "{$scheme}://{$domain}/".ltrim($path, '/');
            }

            $panelPath = config('app.app_panel_path', 'app');

            return rtrim((string) config('app.url'), '/')."/{$panelPath}/".ltrim($path, '/');
        });

        URL::macro('getPublicUrl', function (string $path = ''): string {
            $baseUrl = config('app.url');
            $parsed = parse_url((string) $baseUrl);
            $scheme = $parsed['scheme'] ?? 'https';
            $host = $parsed['host'] ?? 'localhost';

            return $scheme.'://'.$host.'/'.ltrim($path, '/');
        });
    }
}
