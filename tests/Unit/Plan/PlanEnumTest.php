<?php

declare(strict_types=1);

use App\Enums\Plan;
use Relaticle\Chat\Enums\AiModel;

mutates(Plan::class);

it('exposes three cases', function (): void {
    expect(Plan::cases())->toHaveCount(3);
    expect(Plan::Free->value)->toBe('free');
    expect(Plan::Pro->value)->toBe('pro');
    expect(Plan::Enterprise->value)->toBe('enterprise');
});

it('returns Free as default', function (): void {
    expect(Plan::default())->toBe(Plan::Free);
});

it('returns correct labels', function (): void {
    expect(Plan::Free->label())->toBe('Free');
    expect(Plan::Pro->label())->toBe('Pro');
    expect(Plan::Enterprise->label())->toBe('Enterprise');
});

it('returns correct monthly credit allowances', function (): void {
    expect(Plan::Free->credits())->toBe(300);
    expect(Plan::Pro->credits())->toBe(2_000);
    expect(Plan::Enterprise->credits())->toBe(10_000);
});

it('returns correct per-minute rate limits', function (): void {
    expect(Plan::Free->rateLimit())->toBe(10);
    expect(Plan::Pro->rateLimit())->toBe(30);
    expect(Plan::Enterprise->rateLimit())->toBe(60);
});

it('allows only 1.0x models on Free', function (): void {
    expect(Plan::Free->allowedModels())->toBe([
        AiModel::Auto,
        AiModel::ClaudeSonnet,
        AiModel::Gemini3Flash,
    ]);
});

it('allows all models on Pro and Enterprise', function (): void {
    $allCases = AiModel::cases();
    expect(Plan::Pro->allowedModels())->toBe($allCases);
    expect(Plan::Enterprise->allowedModels())->toBe($allCases);
});

it('blocks Opus for Free', function (): void {
    expect(Plan::Free->allowsModel(AiModel::ClaudeOpus))->toBeFalse();
    expect(Plan::Free->allowsModel(AiModel::Gpt5_5))->toBeFalse();
    expect(Plan::Free->allowsModel(AiModel::Gemini31Pro))->toBeFalse();
});

it('allows Sonnet and Auto for Free', function (): void {
    expect(Plan::Free->allowsModel(AiModel::ClaudeSonnet))->toBeTrue();
    expect(Plan::Free->allowsModel(AiModel::Gemini3Flash))->toBeTrue();
    expect(Plan::Free->allowsModel(AiModel::Auto))->toBeTrue();
});

it('allows every model for Pro and Enterprise', function (): void {
    foreach (AiModel::cases() as $model) {
        expect(Plan::Pro->allowsModel($model))->toBeTrue();
        expect(Plan::Enterprise->allowsModel($model))->toBeTrue();
    }
});
