<?php

declare(strict_types=1);

namespace Tests\Evals\Support;

use Relaticle\Chat\Services\ModelRegistry;
use RuntimeException;
use Tests\Evals\Support\ClaudeCode\ClaudeCodeProvider;

final class EvalModels
{
    public const array ALIASES = [
        'gpt-5.5' => ['gpt-5.5', 'gpt-6-luna', 'gpt-5.5-2026-04-23'],
    ];

    public const array RUNNERS = ['api', 'plan'];

    public const array EFFORT_LEVELS = ['low', 'medium', 'high', 'xhigh', 'max'];

    public const array CANDIDATES = [
        ['label' => 'Sonnet 5.5', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'min_plan' => 'free', 'credit_multiplier' => 1.0, 'input_per_mtok' => null, 'output_per_mtok' => null, 'auto' => false, 'enabled' => true, 'capabilities' => ['supports_tools' => true, 'write_guard' => 'api'], 'verified_at' => null],
        ['label' => 'Haiku 5.5', 'provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'min_plan' => 'free', 'credit_multiplier' => 1.0, 'input_per_mtok' => null, 'output_per_mtok' => null, 'auto' => false, 'enabled' => true, 'capabilities' => ['supports_tools' => true, 'write_guard' => 'api'], 'verified_at' => null],
        ['label' => 'Opus 5.5', 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'min_plan' => 'free', 'credit_multiplier' => 1.0, 'input_per_mtok' => null, 'output_per_mtok' => null, 'auto' => false, 'enabled' => true, 'capabilities' => ['supports_tools' => true, 'write_guard' => 'api'], 'verified_at' => null],
        ['label' => 'Fable 5.2', 'provider' => 'anthropic', 'model' => 'claude-fable-5-2', 'min_plan' => 'free', 'credit_multiplier' => 1.0, 'input_per_mtok' => null, 'output_per_mtok' => null, 'auto' => false, 'enabled' => true, 'capabilities' => ['supports_tools' => true, 'write_guard' => 'api'], 'verified_at' => null],
        ['label' => 'GPT 6 Luna', 'provider' => 'openai', 'model' => 'gpt-6-luna', 'min_plan' => 'free', 'credit_multiplier' => 1.0, 'input_per_mtok' => null, 'output_per_mtok' => null, 'auto' => false, 'enabled' => true, 'capabilities' => ['supports_tools' => true, 'write_guard' => 'api'], 'verified_at' => null],
    ];

    public static function register(?string $effort = null, ?string $runner = null): void
    {
        $catalog = config('chat.models', []);
        $offered = array_column($catalog, 'model');
        $models = [
            ...$catalog,
            ...array_filter(self::CANDIDATES, fn (array $candidate): bool => ! in_array($candidate['model'], $offered, true)),
        ];

        if (($runner === null ? self::runner() : self::validRunner($runner)) === 'plan') {
            ClaudeCodeProvider::register();
            $models = array_map(fn (array $model): array => ($model['provider'] ?? null) === 'anthropic' ? [...$model, 'provider' => ClaudeCodeProvider::NAME] : $model, $models);
        }

        config()->set('chat.models', $models);

        $effort = self::effortFrom($effort);

        if ($effort !== null) {
            config()->set('chat.anthropic_effort', $effort);
        }

        app()->forgetInstance(ModelRegistry::class);
    }

    public static function assertPlanServes(string $model): void
    {
        $provider = resolve(ModelRegistry::class)->find($model)?->provider;

        if ($provider !== ClaudeCodeProvider::NAME) {
            throw new RuntimeException("PEST_EVALS_RUNNER=plan runs Claude models only, and {$model} is served by ".($provider ?? 'no provider').'. Use PEST_EVALS_RUNNER=api for it.');
        }
    }

    public static function runner(): string
    {
        return self::validRunner(getenv('PEST_EVALS_RUNNER') ?: 'api');
    }

    public static function validRunner(string $value): string
    {
        return in_array($value, self::RUNNERS, true)
            ? $value
            : throw new RuntimeException(sprintf('PEST_EVALS_RUNNER must be one of %s, got "%s"', implode(', ', self::RUNNERS), $value));
    }

    public static function effortFrom(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return in_array($value, self::EFFORT_LEVELS, true)
            ? $value
            : throw new RuntimeException(sprintf('PEST_EVALS_EFFORT must be one of %s, got "%s"', implode(', ', self::EFFORT_LEVELS), $value));
    }

    public static function assertServed(string $requested, string $served): void
    {
        if ($served === '' || $served === $requested || in_array($served, self::ALIASES[$requested] ?? [], true)) {
            return;
        }

        throw new RuntimeException("Asked for {$requested}, served by {$served}");
    }
}
