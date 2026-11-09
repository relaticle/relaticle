<?php

declare(strict_types=1);

namespace Tests\Evals\Scorers;

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use Tests\Evals\Scorers\Concerns\ReadsEnvelope;

final readonly class CalledAnyOf implements Scorer
{
    use ReadsEnvelope;

    /**
     * @param  list<string>  $names
     */
    public function __construct(private array $names) {}

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        if (($failed = $this->failedTurn($output)) instanceof ScorerResult) {
            return $failed;
        }

        foreach ($this->toolCalls($output) as $call) {
            if (in_array($call['name'], $this->names, true)) {
                return $this->pass("Called {$call['name']}");
            }
        }

        return $this->fail('Called none of '.implode(', ', $this->names));
    }
}
