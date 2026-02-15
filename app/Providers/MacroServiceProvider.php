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
            $trimmedPath = ltrim($path, '/');

            if ($domain = config('app.app_panel_domain')) {
                $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?? 'https';
                $base = "{$scheme}://{$domain}";

                return $trimmedPath !== '' ? "{$base}/{$trimmedPath}" : $base;
            }

            $panelPath = config('app.app_panel_path', 'app');
            $base = rtrim((string) config('app.url'), '/')."/{$panelPath}";

            return $trimmedPath !== '' ? "{$base}/{$trimmedPath}" : $base;
        });

        URL::macro('getPublicUrl', function (string $path = ''): string {
            $base = rtrim((string) config('app.url'), '/');
            $trimmedPath = ltrim($path, '/');

            return $trimmedPath !== '' ? "{$base}/{$trimmedPath}" : $base;
        });
    }
}
