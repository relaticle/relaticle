<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Agents\ModelProbeAgent;
use Relaticle\Chat\Enums\WriteGuard;
use Throwable;

/**
 * Asks a provider whether it will actually serve a chat turn on a given model,
 * by sending one real step built exactly the way a real turn builds it.
 *
 * This exists because of RELATICLE-CRM-6D: a `#[Temperature]` attribute made
 * every Opus 4.7 turn fail with a 400, and nothing short of a real request to
 * the real endpoint could have caught it. A probe that sends a simplified body
 * would have passed while production kept failing, so this one carries the full
 * CrmAssistant surface (every tool schema, the cached system blocks, the
 * tool_choice guard and the effort dial) and lets the provider judge it.
 *
 * It runs through `prompt()`, the same public entry point a real turn uses, so
 * nothing here can quietly drift from production. ModelProbeAgent forbids tool
 * calls, so a settings page can never execute one against the CRM.
 *
 * A pass is remembered forever with what it measured (a model that served a real
 * request does not lose the ability); a failure is never cached, so a provider
 * having a bad minute is simply retried.
 */
final readonly class ModelProbe
{
    private const int TIMEOUT_SECONDS = 30;

    /**
     * @return array{ok: bool, error: string|null, supports_tools: bool, write_guard: string}
     */
    public function __invoke(string $provider, string $model): array
    {
        $cached = Cache::get($this->cacheKey($provider, $model));

        if (is_array($cached)) {
            /** @var array{ok: bool, error: string|null, supports_tools: bool, write_guard: string} $cached */
            return $cached;
        }

        try {
            new ModelProbeAgent(new CrmAssistant)->prompt(
                'Reply with the single word OK.',
                provider: $provider,
                model: $model,
                timeout: self::TIMEOUT_SECONDS,
            );
        } catch (Throwable $e) {
            // Claim nothing on failure, and cache nothing either: a rejection may be
            // a bad model or may be a provider having a bad minute. `prompt` is the
            // weaker guard, and asserting `api` without proof would tell the write
            // path the provider refuses parallel tool calls when it may not.
            return [
                'ok' => false,
                'error' => $this->readableMessage($e),
                'supports_tools' => false,
                'write_guard' => WriteGuard::Prompt->value,
            ];
        }

        $result = [
            'ok' => true,
            'error' => null,
            // The request carried every CrmAssistant tool schema and the provider
            // took it, so tools are usable on this model.
            'supports_tools' => true,
            'write_guard' => $this->writeGuardFor($provider),
        ];

        Cache::forever($this->cacheKey($provider, $model), $result);

        return $result;
    }

    /**
     * `api` means the provider itself refuses parallel tool calls, which is what
     * makes the sequential approval flow unbypassable. Only providers whose options
     * CrmAssistant actually sets that on can claim it; everything else leans on the
     * prompt plus the PendingAction approval gate.
     */
    private function writeGuardFor(string $provider): string
    {
        $options = new CrmAssistant()->providerOptions($provider);

        $enforced = ($options['tool_choice']['disable_parallel_tool_use'] ?? false) === true
            || ($options['parallel_tool_calls'] ?? null) === false;

        return $enforced ? WriteGuard::Api->value : WriteGuard::Prompt->value;
    }

    public function forget(string $provider, string $model): void
    {
        Cache::forget($this->cacheKey($provider, $model));
    }

    private function cacheKey(string $provider, string $model): string
    {
        return "chat:model-probe:{$provider}:{$model}";
    }

    /**
     * Providers bury the useful sentence inside a JSON body that the HTTP client
     * has already stringified into the exception message. Surface that sentence:
     * `temperature is deprecated for this model` is the whole answer, and a bare
     * "HTTP request returned status code 400" is the reason this bug took a live
     * repro to diagnose in the first place.
     */
    private function readableMessage(Throwable $e): string
    {
        // The exception message is truncated to 120 characters by Laravel, which
        // is usually mid-JSON. The response is not, so read the body when there
        // is one and fall back to the message otherwise.
        $nested = $e instanceof RequestException
            ? $e->response->json('error.message')
            : null;

        return str(is_string($nested) ? $nested : $e->getMessage())->squish()->limit(300)->toString();
    }
}
