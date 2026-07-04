<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use Relaticle\Chat\Enums\AiModel;
use Relaticle\Chat\Services\AiModelResolver;

mutates(AiModelResolver::class);

it('falls back to Sonnet when the users preference is not allowed by their plan', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->ai_preferences = ['default_model' => 'claude-opus'];
    $user->save();
    $user->refresh();

    $resolved = resolve(AiModelResolver::class)->resolve($user, null);

    expect($resolved['model'])->toBe('claude-sonnet-4-6');
});

it('honors the users preference when their plan allows it', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->currentTeam->plan = Plan::Pro;
    $user->currentTeam->save();
    $user->ai_preferences = ['default_model' => 'claude-opus'];
    $user->save();
    $user->refresh();

    $resolved = resolve(AiModelResolver::class)->resolve($user, null);

    expect($resolved['model'])->toBe('claude-opus-4-7');
});

it('falls back to Sonnet when an override is disallowed by the plan', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $resolved = resolve(AiModelResolver::class)->resolve($user, 'gpt-5-5');

    expect($resolved['model'])->toBe('claude-sonnet-4-6');
});

it('resolves Auto to Sonnet for any plan', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $resolved = resolve(AiModelResolver::class)->resolve($user, 'auto');

    expect($resolved['model'])->toBe('claude-sonnet-4-6');
});

it('falls back to ClaudeSonnet when a Gemini model is requested', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $user->currentTeam->forceFill(['plan' => Plan::Pro])->save();

    $resolved = (new AiModelResolver)->resolve($user, AiModel::Gemini3Flash->value);

    expect($resolved['provider'])->toBe('anthropic');
    expect($resolved['model'])->toBe(AiModel::ClaudeSonnet->modelId());
});

it('resolves an explicit Ollama request when Ollama is configured', function (): void {
    config()->set('ai.providers.ollama.models.text.default', 'qwen3:14b');

    $user = User::factory()->withPersonalTeam()->create();

    $resolved = resolve(AiModelResolver::class)->resolve($user, 'ollama');

    expect($resolved['provider'])->toBe('ollama');
    expect($resolved['model'])->toBe('qwen3:14b');
});

it('falls back to Sonnet when Ollama is requested but not configured', function (): void {
    config()->set('ai.providers.ollama.models.text.default', null);

    $user = User::factory()->withPersonalTeam()->create();

    $resolved = resolve(AiModelResolver::class)->resolve($user, 'ollama');

    expect($resolved['provider'])->toBe('anthropic');
    expect($resolved['model'])->toBe('claude-sonnet-4-6');
});

it('resolves Auto to Ollama when no cloud provider is configured', function (): void {
    config()->set('ai.providers.anthropic.key', null);
    config()->set('ai.providers.openai.key', null);
    config()->set('ai.providers.ollama.models.text.default', 'qwen3:14b');

    $user = User::factory()->withPersonalTeam()->create();

    $resolved = resolve(AiModelResolver::class)->resolve($user, 'auto');

    expect($resolved['provider'])->toBe('ollama');
    expect($resolved['model'])->toBe('qwen3:14b');
});

it('resolves Auto to Sonnet when Anthropic is configured alongside Ollama', function (): void {
    config()->set('ai.providers.ollama.models.text.default', 'qwen3:14b');

    $user = User::factory()->withPersonalTeam()->create();

    $resolved = resolve(AiModelResolver::class)->resolve($user, 'auto');

    expect($resolved['provider'])->toBe('anthropic');
    expect($resolved['model'])->toBe('claude-sonnet-4-6');
});

it('falls back to an available plan-gated model when the plan allows no configured provider', function (): void {
    config()->set('ai.providers.anthropic.key', null);
    config()->set('ai.providers.ollama.models.text.default', null);

    $user = User::factory()->withPersonalTeam()->create();

    $resolved = resolve(AiModelResolver::class)->resolve($user, 'auto');

    expect($resolved['provider'])->toBe('openai');
    expect($resolved['model'])->toBe('gpt-5.5');
});

it('falls back to Sonnet when no provider is configured at all', function (): void {
    config()->set('ai.providers.anthropic.key', null);
    config()->set('ai.providers.openai.key', null);
    config()->set('ai.providers.ollama.models.text.default', null);

    $user = User::factory()->withPersonalTeam()->create();

    $resolved = resolve(AiModelResolver::class)->resolve($user, 'auto');

    expect($resolved['provider'])->toBe('anthropic');
    expect($resolved['model'])->toBe('claude-sonnet-4-6');
});

it('honors an Ollama default-model preference when configured', function (): void {
    config()->set('ai.providers.ollama.models.text.default', 'llama3.1:70b');

    $user = User::factory()->withPersonalTeam()->create();
    $user->ai_preferences = ['default_model' => 'ollama'];
    $user->save();
    $user->refresh();

    $resolved = resolve(AiModelResolver::class)->resolve($user, null);

    expect($resolved['provider'])->toBe('ollama');
    expect($resolved['model'])->toBe('llama3.1:70b');
});
