<?php

declare(strict_types=1);

namespace Tests\Evals\Scorers;

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use Tests\Evals\Scorers\Concerns\ReadsEnvelope;

final readonly class ToolCalledAtLeast implements Scorer
{
    use ReadsEnvelope;

    /**
     * @param  list<string>  $names
     */
    public function __construct(private array $names, private int $times) {}

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        if (($failed = $this->failedTurn($output)) instanceof ScorerResult) {
            return $failed;
        }

        $matching = array_filter($this->toolCalls($output), fn (array $call): bool => in_array($call['name'], $this->names, true));
        $calls = count($matching);

        if ($calls < $this->times) {
            return $this->fail("Called {$calls} time(s) of ".implode(', ', $this->names).", expected at least {$this->times}");
        }

        return $this->pass("Called {$calls} time(s) of ".implode(', ', $this->names));
    }
}
