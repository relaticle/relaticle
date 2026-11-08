<?php

declare(strict_types=1);

namespace Tests\Evals\Support;

use Closure;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use LogicException;
use Relaticle\Chat\Enums\MessageOrigin;
use Relaticle\Chat\Events\ChatStreamFailed;
use Relaticle\Chat\Events\ChatStreamRetrying;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AgentConversationMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Chat\Services\ModelRegistry;
use Relaticle\Chat\Support\DisplayBlocks;
use Relaticle\Chat\Support\StoredSteps;
use RuntimeException;
use Throwable;

final class RelaTurn
{
    public const int MAX_RETRIES = 3;

    private const int RETRY_DELAY_SECONDS = 2;

    public static ?TurnEnvelope $last = null;

    private readonly string $modelId;

    private readonly string $provider;

    private ?TurnEnvelope $envelope = null;

    public function __construct(
        private readonly EvalWorkspace $workspace,
        ?string $model = null,
        private readonly ?string $conversationId = null,
        private readonly MessageOrigin $origin = MessageOrigin::Typed,
        private readonly ?string $resumesTurnId = null,
        private readonly ?Closure $conversation = null,
    ) {
        $this->modelId = $model ?? self::model();
        $descriptor = resolve(ModelRegistry::class)->find($this->modelId);

        if ($descriptor === null || $descriptor->provider === null) {
            throw new RuntimeException("The model \"{$this->modelId}\" has no provider in the chat catalog.");
        }

        $this->provider = $descriptor->provider;

        AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->workspace->getKey()], [
            'credits_remaining' => 100000,
            'credits_used' => 0,
            'period_starts_at' => now()->startOfMonth(),
            'period_ends_at' => now()->endOfMonth(),
        ]);
    }

    public static function model(): string
    {
        return getenv('PEST_EVALS_RELA_MODEL') ?: 'claude-sonnet-5';
    }

    public function __invoke(string $prompt): string
    {
        $conversationId = $this->nextConversationId();
        $previousId = $this->latestAssistantRow($conversationId)?->id;
        $turnId = (string) Str::ulid();

        try {
            $envelope = $this->runWithRetries($prompt, $conversationId, $turnId, $previousId);
        } catch (Throwable $e) {
            $envelope = TurnEnvelope::failed($e->getMessage());
        }

        self::$last = $this->envelope = $envelope;

        return $envelope->toJson();
    }

    public function envelope(): TurnEnvelope
    {
        return $this->envelope ?? throw new LogicException('The turn has not run yet.');
    }

    public static function envelopeFromRow(AgentConversationMessage $row): TurnEnvelope
    {
        $toolResults = StoredSteps::toolResults($row->steps);
        $usage = self::decode($row->usage);
        $meta = self::decode($row->meta);

        return new TurnEnvelope(
            text: (string) $row->content,
            toolCalls: array_map(fn (array $call): array => [
                'name' => (string) ($call['name'] ?? ''),
                'arguments' => is_array($call['arguments'] ?? null) ? $call['arguments'] : [],
            ], $toolResults),
            toolResultRowIds: array_map(self::rowIdsOf(...), $toolResults),
            replayedBytes: self::replayedBytesOf($toolResults),
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            durationMs: (int) ($meta['duration_ms'] ?? 0),
            error: null,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $toolResults
     */
    public static function replayedBytesOf(array $toolResults): int
    {
        return array_sum(array_map(
            fn (array $toolResult): int => strlen((string) DisplayBlocks::strip($toolResult['result'] ?? '')),
            $toolResults,
        ));
    }

    private function nextConversationId(): string
    {
        if ($this->conversation instanceof Closure) {
            return (string) ($this->conversation)();
        }

        return $this->conversationId ?? (string) $this->workspace->conversation()->getKey();
    }

    private function runWithRetries(string $prompt, string $conversationId, string $turnId, ?string $previousId): TurnEnvelope
    {
        $retryable = false;
        $envelope = $this->runOnce($prompt, $conversationId, $turnId, $previousId, $retryable);

        for ($retry = 0; $retry < self::MAX_RETRIES && $retryable; $retry++) {
            Sleep::for(self::RETRY_DELAY_SECONDS)->seconds();

            $envelope = $this->runOnce($prompt, $conversationId, $turnId, $previousId, $retryable);
        }

        return $envelope;
    }

    private function runOnce(string $prompt, string $conversationId, string $turnId, ?string $previousId, bool &$retryable): TurnEnvelope
    {
        $retrying = null;
        $failure = null;

        Event::listen(ChatStreamRetrying::class, function (ChatStreamRetrying $event) use (&$retrying): void {
            $retrying = "a transient provider error (rate limit, overload or dropped connection) asked for a retry, attempt {$event->attempt} of {$event->maxAttempts}";
        });
        Event::listen(ChatStreamFailed::class, function (ChatStreamFailed $event) use (&$failure): void {
            $failure = $event->message;
        });

        try {
            $this->runJob($prompt, $conversationId, $turnId);
        } finally {
            Event::forget(ChatStreamRetrying::class);
            Event::forget(ChatStreamFailed::class);
        }

        $row = $this->latestAssistantRow($conversationId);

        if ($row !== null && $row->id !== $previousId) {
            $retryable = false;

            return self::envelopeFromRow($row);
        }

        $retryable = $failure === null;

        return TurnEnvelope::failed(implode('; ', array_filter([
            $failure,
            $retrying,
        ])) ?: 'The turn stored no assistant message.');
    }

    private function runJob(string $prompt, string $conversationId, string $turnId): void
    {
        $job = new ProcessChatMessage(
            user: $this->workspace->owner,
            workspace: $this->workspace->workspace,
            message: $this->origin->isTyped() ? $prompt : '',
            conversationId: $conversationId,
            resolved: ['provider' => $this->provider, 'model' => $this->modelId, 'id' => $this->modelId, 'source' => 'explicit'],
            turnId: $turnId,
            origin: $this->origin,
            resumesTurnId: $this->resumesTurnId,
        );

        $job->handle(resolve(CreditService::class));
    }

    private function latestAssistantRow(string $conversationId): ?AgentConversationMessage
    {
        return AgentConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $toolResult
     * @return list<string>
     */
    private static function rowIdsOf(array $toolResult): array
    {
        $decoded = json_decode((string) DisplayBlocks::strip($toolResult['result'] ?? ''), true);
        $rows = is_array($decoded) && is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        return array_values(array_map(
            fn (array $row): string => (string) $row['id'],
            array_filter($rows, fn (mixed $row): bool => is_array($row) && isset($row['id'])),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) ($value ?: '[]'), true);

        return is_array($decoded) ? $decoded : [];
    }
}
