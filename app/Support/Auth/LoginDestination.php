<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Filament\Pages\Dashboard;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Validates and selects the URL a sign-in method redirects to, replacing the
 * per-response ad hoc checks that let an unauthenticated `url.intended` value
 * reach `redirect()->intended()` unchecked (e.g. the passkey and social
 * responses trusted it outright, and the password response only validated it
 * once a workspace already existed).
 */
final readonly class LoginDestination
{
    private const array ALLOWED_SCHEMES = ['http', 'https'];

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

        $path = parse_url($intended, PHP_URL_PATH);

        if (! is_string($path)) {
            return false;
        }

        $segments = array_values(array_filter(explode('/', $path), fn (string $s): bool => $s !== ''));

        $isPanelUrl = ($segments[0] ?? null) === config('app.app_panel_path', 'app');

        // Drop the panel path prefix for path-based panels (/app/{slug}/...).
        // Domain-based panels ({domain}/{slug}/...) have no such prefix.
        if ($isPanelUrl) {
            array_shift($segments);
        }

        $slug = $segments[0] ?? null;

        if ($slug === null) {
            return false;
        }

        // Non-tenant destinations, the OAuth consent screen, invitation links, and
        // shared join links being the ones that matter, carry no workspace to check.
        // Reserved slugs cover the panel-prefixed equivalents (email verification,
        // scheduled-deletion interstitial, passkey/profile screens, ...): a team can
        // never actually hold one of those slugs, so treating them as inaccessible
        // would strand every one of those flows after sign-in.
        if (! $isPanelUrl || in_array($slug, Team::RESERVED_SLUGS, true)) {
            return true;
        }

        $team = Team::query()->where('slug', $slug)->first();

        return $team instanceof Team && $user->belongsToTeam($team);
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
