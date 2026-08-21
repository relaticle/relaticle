<?php

declare(strict_types=1);

use Illuminate\Events\Dispatcher;
use Laravel\Ai\AiManager;
use Laravel\Ai\Gateway\Anthropic\AnthropicGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Relaticle\Chat\Agents\CrmAssistant;

/**
 * The cached-prefix saving depends on laravel/ai merging providerOptions over the
 * request body, which is what lets CrmAssistant replace Anthropic's plain-string
 * `system` with content blocks carrying a cache_control breakpoint. Nothing else
 * fails if that merge order changes upstream — the turn just silently costs full
 * price again — so assert the built request body itself.
 */
mutates(CrmAssistant::class);

function anthropicRequestBodyFor(CrmAssistant $agent): array
{
    $gateway = new AnthropicGateway(new Dispatcher);
    $provider = app(AiManager::class)->textProvider('anthropic');

    $reflection = new ReflectionMethod($gateway, 'buildTextRequestBody');
    $reflection->setAccessible(true);

    return $reflection->invoke(
        $gateway,
        $provider,
        'claude-sonnet-5',
        $agent->instructions(),
        [new UserMessage('hi')],
        [],
        null,
        TextGenerationOptions::forAgent($agent),
    );
}

it('sends the static instructions as a cache_control-marked system block', function (): void {
    $body = anthropicRequestBodyFor(new CrmAssistant);

    expect($body['system'])->toBeArray()
        ->and($body['system'][0]['type'])->toBe('text')
        ->and($body['system'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($body['system'][0]['text'])->toContain('the Relaticle CRM assistant');
});

it('keeps per-turn context out of the cached block', function (): void {
    $body = anthropicRequestBodyFor(
        (new CrmAssistant)->withMentions([
            ['type' => 'company', 'id' => '01COMPANY', 'label' => 'Acme'],
        ])
    );

    expect($body['system'])->toHaveCount(2)
        ->and($body['system'][0])->toHaveKey('cache_control')
        ->and($body['system'][0]['text'])->not->toContain('01COMPANY')
        ->and($body['system'][1])->not->toHaveKey('cache_control')
        ->and($body['system'][1]['text'])->toContain('01COMPANY');
});

it('still forces one tool call per turn alongside the cached system blocks', function (): void {
    $body = anthropicRequestBodyFor(new CrmAssistant);

    expect($body['tool_choice'])->toBe([
        'type' => 'auto',
        'disable_parallel_tool_use' => true,
    ]);
});

it('falls back to a plain string system prompt when caching is disabled', function (): void {
    config()->set('chat.anthropic_prompt_caching', false);

    $body = anthropicRequestBodyFor(new CrmAssistant);

    expect($body['system'])->toBeString()
        ->and($body['system'])->toContain('the Relaticle CRM assistant');
});
