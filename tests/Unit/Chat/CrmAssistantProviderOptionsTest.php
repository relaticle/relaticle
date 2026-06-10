<?php

declare(strict_types=1);

use Laravel\Ai\Enums\Lab;
use Relaticle\Chat\Agents\CrmAssistant;

it('disables parallel tool calls on Anthropic', function (): void {
    $agent = app(CrmAssistant::class);

    $opts = $agent->providerOptions(Lab::Anthropic);

    expect($opts)->toMatchArray([
        'tool_choice' => [
            'type' => 'auto',
            'disable_parallel_tool_use' => true,
        ],
    ]);
});

it('disables parallel tool calls on OpenAI', function (): void {
    $agent = app(CrmAssistant::class);

    $opts = $agent->providerOptions(Lab::OpenAI);

    expect($opts)->toHaveKey('parallel_tool_calls', false);
});

it('returns empty options for unknown providers (Gemini falls to default)', function (): void {
    // Gemini is excluded from #[Provider([...])] because the laravel/ai Gemini
    // driver merges providerOptions() into generationConfig rather than the
    // request top-level, so tool_config (the Gemini parallel-call control) can
    // never be set via this path. Gemini support should be re-enabled once the
    // driver hoists tool_config to the top-level request body.
    $agent = app(CrmAssistant::class);

    expect($agent->providerOptions(Lab::Gemini))->toBe([]);
});
