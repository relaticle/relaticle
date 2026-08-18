<?php

declare(strict_types=1);

namespace App\Health;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

final class ChatProviderCheck extends Check
{
    private string $provider = '';

    private string $model = '';

    /**
     * One check per cloud provider users can actually reach: `auto_chain` picks
     * by configuration rather than liveness, so an outage at any of them breaks
     * chat for whoever landed on it. Providers without a key are left
     * unregistered rather than conditioned with `if()`, because a skipped check
     * counts as a failure in the report.
     *
     * @return list<self>
     */
    public static function forConfiguredProviders(): array
    {
        $checks = [];

        foreach (self::reachableModels() as $provider => $model) {
            $checks[] = self::new()
                ->target($provider, $model)
                ->name("Chat provider: {$provider}");
        }

        return $checks;
    }

    /**
     * Retrieving the model rather than generating from it: a generative probe
     * has to pick a token budget, and a reasoning model spends that budget on
     * thinking before it emits anything. OpenAI rejects a budget under 16 and
     * returns an incomplete response when the budget runs out, so no value both
     * stays free and stays green at an every-minute cadence. A revoked key and
     * a retired model — the two persistent faults this check exists to catch —
     * are precisely what this endpoint reports, as 401 and 404.
     *
     * Provider overload and rate limiting are transient and nothing we can act
     * on, so they only warn.
     */
    public function run(): Result
    {
        $result = Result::make()
            ->meta(['provider' => $this->provider, 'model' => $this->model])
            ->shortSummary($this->model);

        $probe = $this->probe();

        if (! $probe instanceof PendingRequest) {
            return $result->failed("no health probe is defined for chat provider '{$this->provider}'");
        }

        try {
            $response = $probe->get("models/{$this->model}");
        } catch (ConnectionException $e) {
            return $result->warning("{$this->provider} is unreachable: {$e->getMessage()}");
        }

        if ($response->successful()) {
            return $result->ok();
        }

        if ($response->status() === 429 || $response->serverError()) {
            return $result->warning("{$this->provider} is temporarily unavailable: {$this->describe($response)}");
        }

        return $result->failed("{$this->provider} model '{$this->model}' is unavailable: {$this->describe($response)}");
    }

    /**
     * Mirrors how `laravel/ai` builds its client for the same provider, so the
     * probe fails for the same reasons a real chat turn would. A provider the
     * catalogue can register but this method does not know is left null on
     * purpose: `run()` then fails loudly rather than reporting a provider as
     * healthy without ever having contacted it.
     */
    private function probe(): ?PendingRequest
    {
        $key = (string) config("ai.providers.{$this->provider}.key");

        $request = match ($this->provider) {
            'anthropic' => Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => (string) config('ai.providers.anthropic.version', '2023-06-01'),
            ])->baseUrl($this->baseUrl('https://api.anthropic.com/v1')),
            'openai' => Http::withToken($key)
                ->baseUrl($this->baseUrl('https://api.openai.com/v1')),
            default => null,
        };

        return $request?->connectTimeout(5)->timeout(10);
    }

    private function baseUrl(string $default): string
    {
        $url = config("ai.providers.{$this->provider}.url");

        return rtrim(is_string($url) && $url !== '' ? $url : $default, '/');
    }

    private function describe(ClientResponse $response): string
    {
        $message = $response->json('error.message');

        if (! is_string($message) || $message === '') {
            return "HTTP {$response->status()}";
        }

        return "HTTP {$response->status()} - {$message}";
    }

    /**
     * The catalogue is user-selectable, so there is no single configured model
     * per provider. Prefer the entry available on every plan — the one most
     * turns actually use — and fall back to the cheapest plan on offer.
     *
     * @return array<string, string>
     */
    private static function reachableModels(): array
    {
        /** @var Collection<int, array<string, mixed>> $models */
        $models = new Collection(config('chat.models', []));

        return $models
            ->filter(static fn (array $entry): bool => self::isReachable($entry))
            ->sortBy(static fn (array $entry): int => ($entry['min_plan'] ?? null) === 'free' ? 0 : 1)
            ->groupBy('provider')
            ->map(static fn (Collection $entries): string => (string) $entries->first()['model'])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function isReachable(array $entry): bool
    {
        $provider = $entry['provider'] ?? null;
        $model = $entry['model'] ?? null;

        if (! is_string($provider) || ! is_string($model) || $model === '') {
            return false;
        }

        if (($entry['self_hosted'] ?? false) === true) {
            return false;
        }

        if (($entry['supports_tools'] ?? false) !== true) {
            return false;
        }

        return filled(config("ai.providers.{$provider}.key"));
    }

    private function target(string $provider, string $model): self
    {
        $this->provider = $provider;
        $this->model = $model;

        return $this;
    }
}
