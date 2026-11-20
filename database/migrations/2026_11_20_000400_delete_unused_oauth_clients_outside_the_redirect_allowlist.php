<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const int CHUNK_SIZE = 500;

    private const array LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    public function up(): void
    {
        /** @var list<string> $domains */
        $domains = config('mcp.redirect_domains', []);

        if (in_array('*', $domains, true)) {
            return;
        }

        // A client that holds a token stays: a user approved it,
        // and removing it would end a working connection.
        DB::table('oauth_clients')
            ->whereNotExists(fn (Builder $query): Builder => $query
                ->from('oauth_access_tokens')
                ->whereColumn('oauth_access_tokens.client_id', 'oauth_clients.id'))
            ->eachById(function (object $client) use ($domains): void {
                if ($this->redirectsOnlyToAllowedOrigins((string) $client->redirect_uris, $domains)) {
                    return;
                }

                DB::table('oauth_auth_codes')->where('client_id', $client->id)->delete();
                DB::table('oauth_clients')->where('id', $client->id)->delete();
            }, self::CHUNK_SIZE);
    }

    /**
     * @param  list<string>  $domains
     */
    private function redirectsOnlyToAllowedOrigins(string $redirectUris, array $domains): bool
    {
        $uris = json_decode($redirectUris, true);

        if (! is_array($uris)) {
            return true;
        }

        return array_all($uris, fn (mixed $uri): bool => is_string($uri) && $this->isAllowed($uri, $domains));
    }

    /**
     * @param  list<string>  $domains
     */
    private function isAllowed(string $uri, array $domains): bool
    {
        $parts = parse_url($uri);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (in_array($scheme, (array) config('mcp.custom_schemes', []), true)) {
            return true;
        }

        if ($scheme === 'http') {
            return in_array($host, self::LOOPBACK_HOSTS, true);
        }

        if ($scheme !== 'https' || ($parts['port'] ?? 443) !== 443) {
            return false;
        }

        return array_any($domains, fn (string $domain): bool => strtolower((string) parse_url($domain, PHP_URL_SCHEME)) === 'https'
            && strtolower((string) parse_url($domain, PHP_URL_HOST)) === $host);
    }
};
