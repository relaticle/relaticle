<?php

declare(strict_types=1);

namespace Tests\Evals\Scorers;

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use Tests\Evals\Scorers\Concerns\ReadsEnvelope;

final readonly class ReplayedBytesPerRowUnder implements Scorer
{
    use ReadsEnvelope;

    public function __construct(private int $max) {}

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        if (($failed = $this->failedTurn($output)) instanceof ScorerResult) {
            return $failed;
        }

        $envelope = $this->envelope($output);
        $bytes = (int) ($envelope['replayed_bytes'] ?? 0);
        $rows = array_sum(array_map(count(...), $envelope['rendered_row_ids'] ?? []));

        if ($rows === 0) {
            return $this->fail("Replayed {$bytes} bytes and rendered no rows");
        }

        $perRow = intdiv($bytes, $rows);

        if ($perRow >= $this->max) {
            return $this->fail("Replayed {$perRow} bytes per row over {$rows} rows, limit {$this->max}");
        }

        return $this->pass("Replayed {$perRow} bytes per row over {$rows} rows, under {$this->max}");
    }
}
