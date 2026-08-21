<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Tools\ToolNameResolver;

/**
 * Turn laravel/ai's run-level failure events into chat breadcrumbs.
 *
 * ProcessChatMessage only records a failure once the whole job dies, so a turn
 * that was released and retried, or one where a single tool threw partway
 * through, left nothing behind to debug. These events carry the invocation id
 * that correlates the attempt, the step it died on, and how long it ran.
 */
final readonly class AgentRunTelemetry
{
    public function handle(AgentFailed|StepFailed|ToolFailed $event): void
    {
        [$stage, $data] = self::describe($event);

        ChatTelemetry::breadcrumb($stage, $data);
    }

    /**
     * Tool arguments and prompts carry CRM data, so only their shape is recorded.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function describe(AgentFailed|StepFailed|ToolFailed $event): array
    {
        if ($event instanceof ToolFailed) {
            return ['agent.tool_failed', [
                'invocation_id' => $event->invocationId,
                'tool' => ToolNameResolver::resolve($event->tool),
                'argument_keys' => array_keys($event->arguments),
                'time_ms' => (int) round($event->time),
                ...self::exception($event->exception),
            ]];
        }

        if ($event instanceof StepFailed) {
            return ['agent.step_failed', [
                'invocation_id' => $event->invocationId,
                'step' => $event->stepNumber,
                'final_step' => $event->isFinalStep,
                'model' => $event->model,
                'time_ms' => (int) round($event->time),
                ...self::exception($event->exception),
            ]];
        }

        return ['agent.failed', [
            'invocation_id' => $event->invocationId,
            ...self::exception($event->exception),
        ]];
    }

    /**
     * @return array{exception: string, message: string}
     */
    private static function exception(\Throwable $exception): array
    {
        return [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ];
    }
}
