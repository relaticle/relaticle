<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

/**
 * Model ids straight from a provider's own listing endpoint, newest first, so the
 * sysadmin model catalog offers real names instead of a free-text box where a
 * typo becomes a production 404.
 *
 * A provider without a key, without a listing endpoint, or that simply cannot be
 * reached yields an empty list, and the page falls back to whatever is already
 * saved. Never let this throw into a form render: an unreachable vendor must not
 * take the settings page down with it.
 *
 * Only a real listing is cached. A vendor having a bad minute must not blank the
 * model picker for a day, which is the same reason ModelProbe never caches a
 * failure: an empty list is what we could not learn, not what the provider offers.
 */
final readonly class ProviderModelCatalog
{
    private const int TTL_SECONDS = 86400;

    /**
     * @return array<string, int> model id => release timestamp, 0 when unknown
     */
    public function __invoke(string $provider): array
    {
        if (blank(config("ai.providers.{$provider}.key"))) {
            return [];
        }

        $cached = Cache::get($this->cacheKey($provider));

        if (is_array($cached)) {
            /** @var array<string, int> $cached */
            return $cached;
        }

        $models = rescue(fn (): array => $this->fetch($provider), [], report: false);

        if ($models !== []) {
            Cache::put($this->cacheKey($provider), $models, self::TTL_SECONDS);
        }

        return $models;
    }

    public function forget(string $provider): void
    {
        Cache::forget($this->cacheKey($provider));
    }

    private function cacheKey(string $provider): string
    {
        return "chat:model-catalog:{$provider}";
    }

    /**
     * @return array<string, int>
     */
    private function fetch(string $provider): array
    {
        $key = (string) config("ai.providers.{$provider}.key");

        $response = match ($provider) {
            'anthropic' => $this->client()
                ->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
                ->get('https://api.anthropic.com/v1/models', ['limit' => 100]),
            'openai' => $this->client()->withToken($key)
                ->get(rtrim((string) config('ai.providers.openai.url', 'https://api.openai.com/v1'), '/').'/models'),
            default => null,
        };

        if ($response === null) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $response->throw()->json('data', []);

        return collect($rows)
            ->filter(fn (array $row): bool => is_string($row['id'] ?? null))
            ->mapWithKeys(fn (array $row): array => [(string) $row['id'] => $this->releasedAt($row)])
            ->sortDesc()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function releasedAt(array $row): int
    {
        $created = $row['created'] ?? $row['created_at'] ?? null;

        return match (true) {
            is_int($created) => $created,
            is_string($created) => rescue(fn (): int => Date::parse($created)->getTimestamp(), 0, report: false),
            default => 0,
        };
    }

    private function client(): PendingRequest
    {
        return Http::timeout(10)->acceptJson();
    }
}
