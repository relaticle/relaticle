<?php

declare(strict_types=1);

namespace Tests\Evals\Scorers;

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use Tests\Evals\Scorers\Concerns\ReadsEnvelope;

final readonly class AnsweredTurn implements Scorer
{
    use ReadsEnvelope;

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        if (($failed = $this->failedTurn($output)) instanceof ScorerResult) {
            return $failed;
        }

        if (trim($this->replyText($output)) === '' && $this->toolCalls($output) === []) {
            return $this->fail('The turn ended with no reply and no tool call');
        }

        return $this->pass('The turn answered');
    }
}
