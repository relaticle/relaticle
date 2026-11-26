<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mcp;

use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController as BaseOAuthRegisterController;

final class OAuthRegisterController extends BaseOAuthRegisterController
{
    private const array LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    protected function isValidRedirectUri(string $value): bool
    {
        if (! parent::isValidRedirectUri($value)) {
            return false;
        }

        $parts = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return true;
        }

        /** @var array<int, string> $domains */
        $domains = config('mcp.redirect_domains', []);

        if (in_array('*', $domains, true)) {
            return true;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme === 'http') {
            return in_array($host, self::LOOPBACK_HOSTS, true)
                && collect($domains)->contains(fn (string $domain): bool => in_array(strtolower((string) parse_url($domain, PHP_URL_HOST)), self::LOOPBACK_HOSTS, true));
        }

        if (($parts['port'] ?? 443) !== 443) {
            return false;
        }

        return collect($domains)->contains(fn (string $domain): bool => strtolower((string) parse_url($domain, PHP_URL_SCHEME)) === 'https'
            && strtolower((string) parse_url($domain, PHP_URL_HOST)) === $host);
    }

    /**
     * @return array<int, string>
     */
    protected function allowedDomains(): array
    {
        return ['https://', 'http://'];
    }
}
