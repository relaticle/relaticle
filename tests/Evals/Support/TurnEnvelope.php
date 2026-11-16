<?php

declare(strict_types=1);

namespace Tests\Evals\Support;

final readonly class TurnEnvelope
{
    /**
     * @param  list<array{name: string, arguments: array<string, mixed>}>  $toolCalls
     * @param  list<list<string>>  $renderedRowIds
     * @param  list<string>  $stepsText
     * @param  list<array<string, mixed>>  $claudeCode
     */
    public function __construct(
        public string $text,
        public array $toolCalls,
        public array $renderedRowIds,
        public int $replayedBytes,
        public int $inputTokens,
        public int $outputTokens,
        public int $durationMs,
        public ?string $error,
        public array $stepsText = [],
        public string $servedModel = '',
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
        public int $reasoningTokens = 0,
        public int $steps = 0,
        public int $retries = 0,
        public string $runner = 'api',
        public int $parallelToolCalls = 0,
        public array $claudeCode = [],
    ) {}

    public static function failed(string $error): self
    {
        return new self('', [], [], 0, 0, 0, 0, $error);
    }

    public function withRetries(int $retries): self
    {
        return new self(...[...get_object_vars($this), 'retries' => $retries]);
    }

    /**
     * @param  list<array<string, mixed>>  $claudeCode
     */
    public function withRunner(string $runner, array $claudeCode = []): self
    {
        $fields = [...get_object_vars($this), 'runner' => $runner, 'claudeCode' => $claudeCode];

        if ($runner !== 'plan' || ! self::everyRunHasResult($claudeCode)) {
            return new self(...$fields);
        }

        $sum = fn (callable $read): int => array_sum(array_map(fn (array $run): int => (int) $read($run['result']['usage'] ?? []), $claudeCode));

        return new self(...[
            ...$fields,
            'inputTokens' => $sum(fn (array $usage): int => (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['cache_read_input_tokens'] ?? 0) + (int) ($usage['cache_creation_input_tokens'] ?? 0)),
            'outputTokens' => $sum(fn (array $usage): int => (int) ($usage['output_tokens'] ?? 0)),
            'cacheReadTokens' => $sum(fn (array $usage): int => (int) ($usage['cache_read_input_tokens'] ?? 0)),
            'cacheWriteTokens' => $sum(fn (array $usage): int => (int) ($usage['cache_creation_input_tokens'] ?? 0)),
            'reasoningTokens' => $sum(fn (array $usage): int => (int) ($usage['output_tokens_details']['thinking_tokens'] ?? 0)),
        ]);
    }

    public function notionalCostUsd(): ?float
    {
        return self::everyRunHasResult($this->claudeCode)
            ? array_sum(array_map(fn (array $run): float => (float) ($run['result']['total_cost_usd'] ?? 0), $this->claudeCode))
            : null;
    }

    /**
     * @param  list<array<string, mixed>>  $claudeCode
     */
    private static function everyRunHasResult(array $claudeCode): bool
    {
        return $claudeCode !== [] && array_filter($claudeCode, fn (array $run): bool => ! is_array($run['result'] ?? null)) === [];
    }

    public function toJson(): string
    {
        return json_encode([
            'text' => $this->text,
            'steps_text' => $this->stepsText,
            'tool_calls' => $this->toolCalls,
            'rendered_row_ids' => $this->renderedRowIds,
            'replayed_bytes' => $this->replayedBytes,
            'usage' => [
                'input_tokens' => $this->inputTokens,
                'output_tokens' => $this->outputTokens,
            ],
            'duration_ms' => $this->durationMs,
            'error' => $this->error,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function toTrace(): array
    {
        $scored = json_decode($this->toJson(), true, flags: JSON_THROW_ON_ERROR);
        $scored['usage'] += [
            'cache_read' => $this->cacheReadTokens,
            'cache_write' => $this->cacheWriteTokens,
            'reasoning' => $this->reasoningTokens,
        ];

        return $scored + [
            'served_model' => $this->servedModel,
            'steps' => $this->steps,
            'retries' => $this->retries,
            'runner' => $this->runner,
            'parallel_tool_calls' => $this->parallelToolCalls,
        ] + ($this->claudeCode === [] ? [] : ['claude_code' => $this->claudeCode]);
    }
}
