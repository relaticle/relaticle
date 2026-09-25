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
 * Each id carries the vendor's own display name when the vendor publishes one
 * (Anthropic sends `display_name`; OpenAI's listing has no such field), which is
 * what spares an operator from typing the one user-facing string in the catalog.
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
     * @return array<string, array{label: string, released_at: int}> model id => the
     *                                                               vendor's display name (the id itself when it publishes none) and its
     *                                                               release timestamp, 0 when unknown
     */
    public function __invoke(string $provider): array
    {
        if (blank(config("ai.providers.{$provider}.key"))) {
            return [];
        }

        $cached = Cache::get($this->cacheKey($provider));

        if (is_array($cached)) {
            /** @var array<string, array{label: string, released_at: int}> $cached */
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
        // Versioned: a payload cached in the old id => timestamp shape would be read
        // back as one carrying labels and blow up in the form.
        return "chat:model-catalog:v2:{$provider}";
    }

    /**
     * @return array<string, array{label: string, released_at: int}>
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
            ->mapWithKeys(fn (array $row): array => [(string) $row['id'] => [
                'label' => $this->displayName($row),
                'released_at' => $this->releasedAt($row),
            ]])
            ->sortByDesc(fn (array $row): int => $row['released_at'])
            ->all();
    }

    /**
     * The vendor's own name for the model, falling back to the tag. A catalog entry
     * has exactly one user-facing string and this is where it comes from, so an
     * operator adding a model does not have to invent "Sonnet 5" by hand.
     *
     * @param  array<string, mixed>  $row
     */
    private function displayName(array $row): string
    {
        $name = $row['display_name'] ?? null;

        return is_string($name) && $name !== '' ? $name : (string) $row['id'];
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
