<?php

declare(strict_types=1);

namespace Tests\Evals\Support;

use Illuminate\Support\Str;
use LogicException;
use Relaticle\Chat\Enums\MessageOrigin;
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
    ) {
        $this->modelId = $model ?? self::model();
        $this->provider = resolve(ModelRegistry::class)->find($this->modelId)?->provider
            ?? throw new RuntimeException("The model \"{$this->modelId}\" has no provider in the chat catalog.");

        AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->workspace->getKey()], [
            'credits_remaining' => 100000,
            'credits_used' => 0,
            'period_starts_at' => now()->startOfMonth(),
            'period_ends_at' => now()->endOfMonth(),
        ]);
    }

    public static function model(): string
    {
        return (string) env('PEST_EVALS_RELA_MODEL', 'claude-sonnet-5');
    }

    public function __invoke(string $prompt): string
    {
        $conversationId = $this->conversationId ?? (string) $this->workspace->conversation()->getKey();
        $previousId = $this->latestAssistantRow($conversationId)?->id;

        try {
            $this->runJob($prompt, $conversationId);

            $row = $this->latestAssistantRow($conversationId);

            $envelope = $row === null || $row->id === $previousId
                ? TurnEnvelope::failed('The turn stored no assistant message.')
                : self::envelopeFromRow($row);
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

    private function runJob(string $prompt, string $conversationId): void
    {
        $job = new ProcessChatMessage(
            user: $this->workspace->owner,
            workspace: $this->workspace->workspace,
            message: $this->origin->isTyped() ? $prompt : '',
            conversationId: $conversationId,
            resolved: ['provider' => $this->provider, 'model' => $this->modelId, 'id' => $this->modelId, 'source' => 'explicit'],
            turnId: (string) Str::ulid(),
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
