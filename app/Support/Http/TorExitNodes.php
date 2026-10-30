<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

final readonly class TorExitNodes
{
    public const string SOURCE_URL = 'https://check.torproject.org/torbulkexitlist';

    private const string CACHE_KEY = 'tor-exit-nodes';

    private const int CONNECT_TIMEOUT_SECONDS = 5;

    private const int REQUEST_TIMEOUT_SECONDS = 30;

    private const int ATTEMPTS = 2;

    private const int RETRY_BACKOFF_MILLISECONDS = 1000;

    // The bulk list held 1,224 exits on 2026-10-31, so a far shorter body is an error page.
    private const int MINIMUM_EXITS = 500;

    public function contains(string $ip): bool
    {
        $exits = Cache::get(self::CACHE_KEY);

        return is_array($exits) && isset($exits[$ip]);
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     * @throws UnexpectedValueException
     */
    public function refresh(): int
    {
        $body = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->retry(self::ATTEMPTS, self::RETRY_BACKOFF_MILLISECONDS)
            ->get(self::SOURCE_URL)
            ->body();

        $exits = collect(preg_split('/\R/', $body) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => filter_var($line, FILTER_VALIDATE_IP) !== false)
            ->unique()
            ->values();

        throw_if(
            $exits->count() < self::MINIMUM_EXITS,
            UnexpectedValueException::class,
            "The Tor exit list returned {$exits->count()} addresses, fewer than the ".self::MINIMUM_EXITS.' expected.',
        );

        Cache::forever(self::CACHE_KEY, array_fill_keys($exits->all(), true));

        return $exits->count();
    }
}
