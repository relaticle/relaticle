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
                $parsed = parse_url((string) config('app.url'));
                $scheme = $parsed['scheme'] ?? 'https';
                $port = isset($parsed['port']) ? ":{$parsed['port']}" : '';
                $base = "{$scheme}://{$domain}{$port}";

                return $trimmedPath !== '' ? "{$base}/{$trimmedPath}" : $base;
            }

            $panelPath = config('app.app_panel_path', 'app');
            $base = rtrim((string) config('app.url'), '/')."/{$panelPath}";

            return $trimmedPath !== '' ? "{$base}/{$trimmedPath}" : $base;
        });

        URL::macro('getMcpUrl', function (): string {
            if ($domain = config('app.mcp_domain')) {
                $parsed = parse_url((string) config('app.url'));
                $scheme = $parsed['scheme'] ?? 'https';
                $port = isset($parsed['port']) ? ":{$parsed['port']}" : '';

                return "{$scheme}://{$domain}{$port}";
            }

            return rtrim((string) config('app.url'), '/').'/mcp';
        });

        URL::macro('getApiUrl', function (string $path = ''): string {
            $trimmedPath = ltrim($path, '/');

            if ($domain = config('app.api_domain')) {
                $parsed = parse_url((string) config('app.url'));
                $scheme = $parsed['scheme'] ?? 'https';
                $port = isset($parsed['port']) ? ":{$parsed['port']}" : '';
                $base = "{$scheme}://{$domain}{$port}";

                return $trimmedPath !== '' ? "{$base}/{$trimmedPath}" : $base;
            }

            $base = rtrim((string) config('app.url'), '/').'/api';

            return $trimmedPath !== '' ? "{$base}/{$trimmedPath}" : $base;
        });

        URL::macro('getPublicUrl', function (string $path = ''): string {
            $base = rtrim((string) config('app.url'), '/');
            $trimmedPath = ltrim($path, '/');

            return $trimmedPath !== '' ? "{$base}/{$trimmedPath}" : $base;
        });
    }
}
