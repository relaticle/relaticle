<?php

declare(strict_types=1);

namespace Tests\Evals\Scorers;

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use Tests\Evals\Scorers\Concerns\ReadsEnvelope;

final readonly class NoEmDash implements Scorer
{
    use ReadsEnvelope;

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        if (($failed = $this->failedTurn($output)) instanceof ScorerResult) {
            return $failed;
        }

        if (preg_match('/\x{2014}/u', $this->text($output)) === 1) {
            return $this->fail('Em dash in the reply');
        }

        return $this->pass('No em dash in the reply');
    }
}
