<?php

declare(strict_types=1);

namespace App\Health;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Facades\Prism;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Throwable;

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
     * Provider overload and rate limiting are transient and nothing we can act
     * on, so they only warn. Persistent faults — a revoked key, a retired
     * model — are the ones worth failing the health report over.
     */
    public function run(): Result
    {
        $result = Result::make()
            ->meta(['provider' => $this->provider, 'model' => $this->model])
            ->shortSummary($this->model);

        try {
            Prism::text()
                ->using($this->provider, $this->model)
                ->withMaxTokens(1)
                ->withPrompt('Hi')
                ->generate();

            return $result->ok();
        } catch (PrismRateLimitedException|PrismProviderOverloadedException|ConnectionException $e) {
            return $result->warning("{$this->provider} is temporarily unavailable: {$e->getMessage()}");
        } catch (Throwable $e) {
            return $result->failed("{$this->provider} model '{$this->model}' is unavailable: {$e->getMessage()}");
        }
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
