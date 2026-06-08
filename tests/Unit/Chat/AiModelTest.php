<?php

declare(strict_types=1);

use Relaticle\Chat\Enums\AiModel;

mutates(AiModel::class);

it('returns the latest Anthropic model ids', function (): void {
    expect(AiModel::ClaudeSonnet->modelId())->toBe('claude-sonnet-4-6')
        ->and(AiModel::ClaudeOpus->modelId())->toBe('claude-opus-4-7');
});

it('returns null model id for Auto', function (): void {
    expect(AiModel::Auto->modelId())->toBeNull();
});

it('maps each anthropic model to anthropic provider', function (): void {
    expect(AiModel::ClaudeSonnet->provider())->toBe('anthropic')
        ->and(AiModel::ClaudeOpus->provider())->toBe('anthropic');
});

it('maps each OpenAI model to openai provider', function (): void {
    expect(AiModel::Gpt5_5->provider())->toBe('openai')
        ->and(AiModel::Gpt5_4->provider())->toBe('openai');
});

it('maps each Gemini model to gemini provider', function (): void {
    expect(AiModel::Gemini3Flash->provider())->toBe('gemini')
        ->and(AiModel::Gemini31Pro->provider())->toBe('gemini');
});

it('resolves a multiplier for every known model id', function (): void {
    expect(AiModel::multiplierForModelId('claude-opus-4-7'))->toBe(3.0)
        ->and(AiModel::multiplierForModelId('claude-sonnet-4-6'))->toBe(1.0)
        ->and(AiModel::multiplierForModelId('gpt-5.5'))->toBe(1.5)
        ->and(AiModel::multiplierForModelId('gpt-5.4'))->toBe(1.5)
        ->and(AiModel::multiplierForModelId('gemini-3-flash'))->toBe(1.0)
        ->and(AiModel::multiplierForModelId('gemini-3.1-pro'))->toBe(1.5);
});

it('falls back to 1.0 for an unknown model id', function (): void {
    expect(AiModel::multiplierForModelId('definitely-not-a-real-model'))->toBe(1.0);
});

it('keeps every non-Auto case fully wired with provider, modelId and multiplier', function (AiModel $case): void {
    expect($case->provider())->not->toBeNull()
        ->and($case->modelId())->not->toBeNull()
        ->and($case->creditMultiplier())->toBeGreaterThanOrEqual(1.0);
})->with(array_filter(
    AiModel::cases(),
    fn (AiModel $case): bool => $case !== AiModel::Auto,
));
