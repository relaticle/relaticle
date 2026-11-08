<?php

declare(strict_types=1);

namespace Tests\Evals\Support;

final readonly class TurnEnvelope
{
    /**
     * @param  list<array{name: string, arguments: array<string, mixed>}>  $toolCalls
     * @param  list<list<string>>  $toolResultRowIds
     */
    public function __construct(
        public string $text,
        public array $toolCalls,
        public array $toolResultRowIds,
        public int $replayedBytes,
        public int $inputTokens,
        public int $outputTokens,
        public int $durationMs,
        public ?string $error,
    ) {}

    public static function failed(string $error): self
    {
        return new self('', [], [], 0, 0, 0, 0, $error);
    }

    public function toJson(): string
    {
        return json_encode([
            'text' => $this->text,
            'tool_calls' => $this->toolCalls,
            'tool_result_row_ids' => $this->toolResultRowIds,
            'replayed_bytes' => $this->replayedBytes,
            'usage' => [
                'input_tokens' => $this->inputTokens,
                'output_tokens' => $this->outputTokens,
            ],
            'duration_ms' => $this->durationMs,
            'error' => $this->error,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
