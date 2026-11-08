<?php

declare(strict_types=1);

namespace Tests\Evals\Scorers;

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use Tests\Evals\Scorers\Concerns\ReadsEnvelope;

final readonly class OneClosingQuestionAtMost implements Scorer
{
    use ReadsEnvelope;

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        $questions = (int) preg_match_all('/[?\x{061F}\x{FF1F}]/u', $this->text($output));

        if ($questions > 1) {
            return $this->fail("{$questions} question marks in the reply");
        }

        return $this->pass('At most one question in the reply');
    }
}
