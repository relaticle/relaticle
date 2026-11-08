<?php

declare(strict_types=1);

namespace Tests\Evals\Scorers;

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use Tests\Evals\Scorers\Concerns\ReadsEnvelope;

final readonly class NoHeadingLine implements Scorer
{
    use ReadsEnvelope;

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        if (($failed = $this->failedTurn($output)) instanceof ScorerResult) {
            return $failed;
        }

        if (preg_match('/^\s*(#{1,6}\s+\S.*|\*\*[^*\n]{2,40}\*\*\s*:?\s*)$/m', $this->text($output), $match) === 1) {
            return $this->fail('Heading line in the reply: '.trim($match[0]));
        }

        return $this->pass('No heading line in the reply');
    }
}
