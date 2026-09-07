<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Filament\Pages\Dashboard;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class LoginDestination
{
    private const array ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * @var list<string>
     */
    // Bare panel root always redirects to the caller's own tenant already.
    private const array META_ROUTE_NAMES = ['filament.app.tenant'];

    public function resolve(User $user, ?string $intended): string
    {
        $fallback = $this->fallback($user);

        if (! is_string($intended) || $intended === '') {
            return $fallback;
        }

        return $this->isAccessible($intended, $user) ? $intended : $fallback;
    }

    private function fallback(User $user): string
    {
        return $user->currentTeam
            ? Dashboard::getUrl(['tenant' => $user->currentTeam])
            : Filament::getPanel('app')->getUrl();
    }

    private function isAccessible(string $intended, User $user): bool
    {
        if (! $this->isSameSite($intended)) {
            return false;
        }

        $route = $this->matchRoute($intended);

        if (! $route instanceof RoutingRoute || in_array($route->getName(), self::META_ROUTE_NAMES, true)) {
            return false;
        }

        if (! $route->hasParameter('tenant')) {
            return true;
        }

        $tenantSlug = $route->parameter('tenant');

        if (! is_string($tenantSlug)) {
            return false;
        }

        $team = Team::query()->where('slug', $tenantSlug)->first();

        return $team instanceof Team && $user->belongsToTeam($team);
    }

    // A relative URL is matched against the panel domain when one is configured,
    // since that is the only origin a relative destination can mean there.
    private function matchRoute(string $intended): ?RoutingRoute
    {
        $path = parse_url($intended, PHP_URL_PATH) ?: '/';
        $query = parse_url($intended, PHP_URL_QUERY);
        $host = parse_url($intended, PHP_URL_HOST);

        $request = Request::create(is_string($query) ? "{$path}?{$query}" : $path, 'GET');

        $panelDomain = config('app.app_panel_domain');

        if (is_string($host)) {
            $request->headers->set('HOST', $host);
        } elseif (is_string($panelDomain) && $panelDomain !== '') {
            $request->headers->set('HOST', $panelDomain);
        }

        try {
            return resolve(Router::class)->getRoutes()->match($request);
        } catch (NotFoundHttpException) {
            return null;
        }
    }

    private function isSameSite(string $intended): bool
    {
        if ($intended === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $intended) === 1) {
            return false;
        }

        $host = parse_url($intended, PHP_URL_HOST);

        if ($host === null || $host === false) {
            return str_starts_with($intended, '/') && ! str_starts_with($intended, '//');
        }

        if (parse_url($intended, PHP_URL_USER) !== null) {
            return false;
        }

        $scheme = parse_url($intended, PHP_URL_SCHEME);

        if (! is_string($scheme) || ! in_array(mb_strtolower($scheme), self::ALLOWED_SCHEMES, true)) {
            return false;
        }

        $port = $this->port($intended);

        return array_any($this->allowedOrigins(), fn (array $origin): bool => $origin['host'] === $host && $origin['scheme'] === mb_strtolower($scheme) && $origin['port'] === $port);
    }

    /**
     * @return list<array{host: string, scheme: string, port: int|null}>
     */
    private function allowedOrigins(): array
    {
        $urls = array_filter([
            (string) config('app.url'),
            $this->domainUrl(config('app.app_panel_domain')),
            $this->domainUrl(config('app.mcp_domain')),
            $this->domainUrl(config('app.api_domain')),
        ], fn (?string $url): bool => is_string($url) && $url !== '');

        $origins = [];

        foreach ($urls as $url) {
            $host = parse_url($url, PHP_URL_HOST);

            if (! is_string($host) || $host === '') {
                continue;
            }

            $scheme = parse_url($url, PHP_URL_SCHEME);

            $origins[] = [
                'host' => $host,
                'scheme' => is_string($scheme) ? mb_strtolower($scheme) : 'https',
                'port' => $this->port($url),
            ];
        }

        return $origins;
    }

    private function domainUrl(mixed $domain): ?string
    {
        if (! is_string($domain) || $domain === '') {
            return null;
        }

        $appUrl = (string) config('app.url');
        $scheme = parse_url($appUrl, PHP_URL_SCHEME);
        $port = $this->port($appUrl);

        $base = (is_string($scheme) ? $scheme : 'https').'://'.$domain;

        return $port ? "{$base}:{$port}" : $base;
    }

    private function port(string $url): ?int
    {
        $port = parse_url($url, PHP_URL_PORT);

        return is_int($port) ? $port : null;
    }
}
