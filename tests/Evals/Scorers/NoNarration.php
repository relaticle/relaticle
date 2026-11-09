<?php

declare(strict_types=1);

namespace Tests\Evals\Scorers;

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use Tests\Evals\Scorers\Concerns\ReadsEnvelope;

final readonly class NoNarration implements Scorer
{
    use ReadsEnvelope;

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        if (($failed = $this->failedTurn($output)) instanceof ScorerResult) {
            return $failed;
        }

        if (preg_match('/^\s*(let me|i.ll (now|first|check|look|fetch|pull)|first, let me|allow me to|i will now)/imu', $this->text($output), $match) === 1) {
            return $this->fail('Narrates the next step: '.trim($match[0]));
        }

        return $this->pass('No narration of the next step');
    }
}
