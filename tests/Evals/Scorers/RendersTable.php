<?php

declare(strict_types=1);

namespace Tests\Evals\Scorers;

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use Tests\Evals\Scorers\Concerns\ReadsEnvelope;

final readonly class RendersTable implements Scorer
{
    use ReadsEnvelope;

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        if (($failed = $this->failedTurn($output)) instanceof ScorerResult) {
            return $failed;
        }

        $sets = $this->envelope($output)['rendered_row_ids'] ?? [];

        foreach (is_array($sets) ? $sets : [] as $ids) {
            if (is_array($ids) && $ids !== []) {
                return $this->pass('A table rendered '.count($ids).' rows');
            }
        }

        return $this->fail('No table rendered for a request to see records');
    }
}
