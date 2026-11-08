<?php

declare(strict_types=1);

namespace Tests\Evals\Scorers;

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use Tests\Evals\Scorers\Concerns\ReadsEnvelope;

final readonly class ReplayedBytesUnder implements Scorer
{
    use ReadsEnvelope;

    public function __construct(private int $max) {}

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        $bytes = (int) ($this->envelope($output)['replayed_bytes'] ?? 0);

        if ($bytes >= $this->max) {
            return $this->fail("Replayed {$bytes} bytes, limit {$this->max}");
        }

        return $this->pass("Replayed {$bytes} bytes, under {$this->max}");
    }
}
