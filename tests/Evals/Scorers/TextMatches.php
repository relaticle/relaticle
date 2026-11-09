<?php

declare(strict_types=1);

namespace Tests\Evals\Scorers;

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use Tests\Evals\Scorers\Concerns\ReadsEnvelope;

final readonly class TextMatches implements Scorer
{
    use ReadsEnvelope;

    public function __construct(private string $pattern, private bool $negate = false) {}

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        if (($failed = $this->failedTurn($output)) instanceof ScorerResult) {
            return $failed;
        }

        $matches = preg_match($this->pattern, $this->replyText($output)) === 1;

        if ($matches === $this->negate) {
            return $this->fail(($this->negate ? 'The reply matches ' : 'The reply does not match ').$this->pattern);
        }

        return $this->pass(($this->negate ? 'The reply avoids ' : 'The reply matches ').$this->pattern);
    }
}
