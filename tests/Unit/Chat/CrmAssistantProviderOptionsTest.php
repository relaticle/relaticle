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

it('marks the static system block with a cache breakpoint on Anthropic', function (): void {
    $agent = app(CrmAssistant::class);

    $system = $agent->providerOptions(Lab::Anthropic)['system'];

    expect($system[0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($system[0]['text'])->toBe($agent->staticInstructions())
        ->and($system[1]['text'])->toBe($agent->dynamicInstructions())
        ->and($system[1])->not->toHaveKey('cache_control');
});

it('keeps per-turn dynamic context in the uncached system block', function (): void {
    $agent = app(CrmAssistant::class)->withSupersededProposals([
        ['operation' => 'create', 'entity_type' => 'task', 'label' => 'Cache probe'],
    ]);

    $system = $agent->providerOptions(Lab::Anthropic)['system'];

    expect($system[0]['text'])->not->toContain('Cache probe')
        ->and($system[1]['text'])->toContain('Cache probe');
});

it('omits the system override when prompt caching is disabled', function (): void {
    config()->set('chat.anthropic_prompt_caching', false);

    expect(app(CrmAssistant::class)->providerOptions(Lab::Anthropic))->not->toHaveKey('system');
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
