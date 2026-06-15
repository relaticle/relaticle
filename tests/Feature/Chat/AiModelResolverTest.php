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
