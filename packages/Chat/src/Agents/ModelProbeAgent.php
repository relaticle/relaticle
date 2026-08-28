<?php

declare(strict_types=1);

namespace Relaticle\Chat\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Promptable;
use Laravel\Ai\ToolChoice;

/**
 * CrmAssistant's exact request surface, with the model forbidden from calling
 * anything.
 *
 * ModelProbe needs the provider to judge the real request: every tool schema, the
 * cached system blocks, the effort dial. A probe that trimmed any of that would
 * pass while production kept failing, which is the failure RELATICLE-CRM-6D
 * actually was. But a settings page must not be able to touch CRM data, so
 * `tool_choice: none` goes on instead: the schemas are still sent and still
 * validated by the provider, and no tool call can come back to execute.
 *
 * The choice rides on the attribute rather than providerOptions() because the
 * Anthropic gateway array_merges providerOptions LAST, over the body: a
 * `tool_choice` returned from there would overwrite the guard. Stripping the
 * assistant's own key and letting the gateway map the attribute also keeps the
 * per-provider shape correct (Anthropic and OpenAI spell "none" differently).
 */
#[MaxSteps(1)]
#[MaxTokens(16)]
#[ToolChoice(ToolChoice::none)]
final readonly class ModelProbeAgent implements Agent, HasProviderOptions, HasTools
{
    use Promptable;

    public function __construct(private CrmAssistant $assistant) {}

    public function instructions(): string
    {
        return $this->assistant->instructions();
    }

    /**
     * @return list<Tool>
     */
    public function tools(): array
    {
        return $this->assistant->tools();
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $options = $this->assistant->providerOptions($provider);

        unset($options['tool_choice']);

        return $options;
    }

    /**
     * Sampling parameters are read off the AGENT that runs the prompt, so
     * delegating them is what makes this probe able to catch the bug it exists
     * for: a `#[Temperature]` on CrmAssistant is rejected by every current
     * Anthropic model, and a probe carrying its own (absent) sampling config
     * would sail through while production failed on every turn. Verified by
     * reintroducing the attribute and watching the probe go red.
     *
     * maxSteps, maxTokens and toolChoice are deliberately NOT delegated: those
     * are the probe's own cost and safety caps, and none of them can turn an
     * accepted request into a rejected one.
     */
    public function temperature(): ?float
    {
        return TextGenerationOptions::forAgent($this->assistant)->temperature;
    }

    public function topP(): ?float
    {
        return TextGenerationOptions::forAgent($this->assistant)->topP;
    }
}
