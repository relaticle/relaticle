<?php

declare(strict_types=1);

namespace Tests\Evals\Scorers;

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use Tests\Evals\Scorers\Concerns\ReadsEnvelope;

final readonly class EveryRecordCarries implements Scorer
{
    use ReadsEnvelope;

    public function __construct(
        private string $tool,
        private string $key,
        private string $pattern = '/./',
        private int $records = 1,
    ) {}

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        if (($failed = $this->failedTurn($output)) instanceof ScorerResult) {
            return $failed;
        }

        $total = 0;

        foreach ($this->toolCalls($output) as $call) {
            if ($call['name'] !== $this->tool) {
                continue;
            }

            $records = is_array($call['arguments']['records'] ?? null) ? $call['arguments']['records'] : [];

            foreach ($records as $record) {
                $total++;
                $value = is_array($record) ? ($record[$this->key] ?? null) : null;
                $text = match (true) {
                    $value === null, $value === [] => '',
                    is_scalar($value) => (string) $value,
                    default => (string) json_encode($value),
                };

                if (preg_match($this->pattern, $text) !== 1) {
                    return $this->fail("A {$this->tool} record has no usable {$this->key}: {$text}");
                }
            }
        }

        if ($total < $this->records) {
            return $this->fail("{$this->tool} proposed {$total} record(s), expected at least {$this->records}");
        }

        return $this->pass("Every {$this->tool} record carries {$this->key}");
    }
}
