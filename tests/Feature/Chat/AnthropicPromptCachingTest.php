<?php

declare(strict_types=1);

use Illuminate\Events\Dispatcher;
use Laravel\Ai\AiManager;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\Anthropic\AnthropicGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\ToolChoice;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Agents\ModelProbeAgent;

/**
 * The cached-prefix saving depends on laravel/ai merging providerOptions over the
 * request body, which is what lets CrmAssistant replace Anthropic's plain-string
 * `system` with content blocks carrying a cache_control breakpoint. Nothing else
 * fails if that merge order changes upstream, the turn just silently costs full
 * price again, so assert the built request body itself.
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

/**
 * Anthropic removed sampling parameters on Opus 4.7 and every model after it, and
 * rejects a request carrying one with `temperature is deprecated for this model`.
 * A #[Temperature] attribute on the agent is enough to break every turn on those
 * models, which is what took Opus 4.7 down in production (RELATICLE-CRM-6D).
 */
it('sends no sampling parameters, which the current Anthropic models reject', function (): void {
    $body = anthropicRequestBodyFor(new CrmAssistant);

    expect($body)->not->toHaveKey('temperature')
        ->and($body)->not->toHaveKey('top_p');
});

it('sends the configured reasoning effort, the only quality dial these models still expose', function (): void {
    config()->set('chat.anthropic_effort', 'low');

    $body = anthropicRequestBodyFor(new CrmAssistant);

    expect($body['output_config'])->toBe(['effort' => 'low']);
});

it('sends no effort at all when the configured value is not one Anthropic accepts', function (): void {
    config()->set('chat.anthropic_effort', 'ludicrous');

    $body = anthropicRequestBodyFor(new CrmAssistant);

    expect($body)->not->toHaveKey('output_config');
});

/**
 * ModelProbe only earns its place if it fails on the request CrmAssistant would
 * really send. Sampling parameters are read off the agent running the prompt, so
 * a probe agent that carried its own (absent) sampling config would sail through
 * while production failed on every turn. That is exactly RELATICLE-CRM-6D, and
 * exactly what the first version of this probe did.
 */
it('probes with the assistant\'s own sampling parameters, not its own', function (): void {
    $assistant = new CrmAssistant;
    $probe = new ModelProbeAgent($assistant);

    $assistantOptions = TextGenerationOptions::forAgent($assistant);
    $probeOptions = TextGenerationOptions::forAgent($probe);

    expect($probeOptions->temperature)->toBe($assistantOptions->temperature)
        ->and($probeOptions->topP)->toBe($assistantOptions->topP);
});

it('forbids the probe from calling a tool while still sending every tool schema', function (): void {
    $probe = new ModelProbeAgent(new CrmAssistant);

    $options = TextGenerationOptions::forAgent($probe);

    expect($options->toolChoice?->mode)->toBe(ToolChoice::none)
        ->and($probe->providerOptions(Lab::Anthropic))->not->toHaveKey('tool_choice')
        ->and($probe->tools())->toHaveSameSize((new CrmAssistant)->tools());
});

it('falls back to a plain string system prompt when caching is disabled', function (): void {
    config()->set('chat.anthropic_prompt_caching', false);

    $body = anthropicRequestBodyFor(new CrmAssistant);

    expect($body['system'])->toBeString()
        ->and($body['system'])->toContain('the Relaticle CRM assistant');
});
