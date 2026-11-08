<?php

declare(strict_types=1);

namespace Tests\Evals\Scorers\Concerns;

use Pest\Evals\Scorers\ScorerResult;

trait ReadsEnvelope
{
    /**
     * @return array<string, mixed>
     */
    private function envelope(string $output): array
    {
        $decoded = json_decode($output, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function text(string $output): string
    {
        $text = $this->envelope($output)['text'] ?? '';

        $text = is_string($text) ? $text : '';
        $steps = $this->envelope($output)['steps_text'] ?? [];
        $earlier = is_array($steps) ? array_filter($steps, fn (mixed $step): bool => is_string($step) && trim($step) !== '' && ! str_contains($text, trim($step))) : [];

        return implode("\n", [...$earlier, $text]);
    }

    private function replyText(string $output): string
    {
        $text = $this->envelope($output)['text'] ?? '';

        return is_string($text) ? $text : '';
    }

    /**
     * @return list<array{name: string, arguments: array<string, mixed>}>
     */
    private function toolCalls(string $output): array
    {
        $calls = $this->envelope($output)['tool_calls'] ?? [];

        if (! is_array($calls)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $call): array => [
            'name' => is_array($call) && is_string($call['name'] ?? null) ? $call['name'] : '',
            'arguments' => is_array($call) && is_array($call['arguments'] ?? null) ? $call['arguments'] : [],
        ], $calls));
    }

    protected function failedTurn(string $output): ?ScorerResult
    {
        $error = $this->envelope($output)['error'] ?? null;

        if ($error === null) {
            return null;
        }

        return $this->fail('The turn failed: '.(is_string($error) ? $error : json_encode($error)));
    }

    private function pass(string $reasoning): ScorerResult
    {
        return new ScorerResult(1.0, $reasoning, static::class);
    }

    private function fail(string $reasoning): ScorerResult
    {
        return new ScorerResult(0.0, $reasoning, static::class);
    }
}
