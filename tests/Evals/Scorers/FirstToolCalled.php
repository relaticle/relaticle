<?php

declare(strict_types=1);

namespace Tests\Evals\Scorers;

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use Tests\Evals\Scorers\Concerns\ReadsEnvelope;

final readonly class FirstToolCalled implements Scorer
{
    use ReadsEnvelope;

    public function __construct(private string $name) {}

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        if (($failed = $this->failedTurn($output)) instanceof ScorerResult) {
            return $failed;
        }

        $first = $this->toolCalls($output)[0]['name'] ?? null;

        if ($first !== $this->name) {
            return $this->fail("The first tool call was expected to be {$this->name}, got ".($first ?? 'none'));
        }

        return $this->pass("The first tool call was {$this->name}");
    }
}
