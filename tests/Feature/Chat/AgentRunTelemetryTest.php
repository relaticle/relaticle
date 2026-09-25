<?php

declare(strict_types=1);

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Support\AgentRunTelemetry;

mutates(AgentRunTelemetry::class);

function failingChatTool(): Tool
{
    return new class implements Tool
    {
        public function description(): string
        {
            return 'Updates a person.';
        }

        public function handle(Request $request): string
        {
            return '';
        }

        /**
         * @return array<string, Type>
         */
        public function schema(JsonSchema $schema): array
        {
            return [];
        }

        public function name(): string
        {
            return 'UpdatePersonTool';
        }
    };
}

it('listens for the run failure events laravel/ai reports', function (): void {
    Event::fake();

    Event::assertListening(AgentFailed::class, AgentRunTelemetry::class);
    Event::assertListening(StepFailed::class, AgentRunTelemetry::class);
    Event::assertListening(ToolFailed::class, AgentRunTelemetry::class);
});

it('records which tool failed and how long it ran, never its argument values', function (): void {
    [$stage, $data] = AgentRunTelemetry::describe(new ToolFailed(
        invocationId: 'inv-1',
        toolInvocationId: 'tool-inv-1',
        agent: new CrmAssistant,
        tool: failingChatTool(),
        arguments: ['id' => '01PERSON', 'emails' => ['ceo@acme.test']],
        exception: new RuntimeException('tool blew up'),
        time: 12.7,
    ));

    expect($stage)->toBe('agent.tool_failed')
        ->and($data['tool'])->toBe('UpdatePersonTool')
        ->and($data['argument_keys'])->toBe(['id', 'emails'])
        ->and($data['time_ms'])->toBe(13)
        ->and($data['exception'])->toBe(RuntimeException::class)
        ->and($data['message'])->toBe('tool blew up');

    expect(json_encode($data))->not->toContain('01PERSON')
        ->and(json_encode($data))->not->toContain('ceo@acme.test');
});

it('records the step a turn died on', function (): void {
    [$stage, $data] = AgentRunTelemetry::describe(new StepFailed(
        invocationId: 'inv-1',
        stepNumber: 3,
        agent: new CrmAssistant,
        provider: app(AiManager::class)->textProvider('anthropic'),
        model: 'claude-sonnet-5',
        isFinalStep: false,
        exception: new RuntimeException('provider hung up'),
        time: 812.4,
    ));

    expect($stage)->toBe('agent.step_failed')
        ->and($data['step'])->toBe(3)
        ->and($data['final_step'])->toBeFalse()
        ->and($data['model'])->toBe('claude-sonnet-5')
        ->and($data['time_ms'])->toBe(812)
        ->and($data['invocation_id'])->toBe('inv-1');
});

it('records a terminal run failure without echoing the prompt', function (): void {
    [$stage, $data] = AgentRunTelemetry::describe(new AgentFailed(
        invocationId: 'inv-1',
        prompt: new AgentPrompt(
            new CrmAssistant,
            'delete every company named Acme',
            [],
            app(AiManager::class)->textProvider('anthropic'),
            'claude-sonnet-5',
        ),
        exception: new RuntimeException('out of retries'),
    ));

    expect($stage)->toBe('agent.failed')
        ->and($data['invocation_id'])->toBe('inv-1')
        ->and($data['exception'])->toBe(RuntimeException::class);

    expect(json_encode($data))->not->toContain('Acme');
});
