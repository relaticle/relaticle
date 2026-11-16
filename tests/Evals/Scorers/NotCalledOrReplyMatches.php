<?php

declare(strict_types=1);

namespace Tests\Evals\Scorers;

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use Tests\Evals\Scorers\Concerns\ReadsEnvelope;

final readonly class NotCalledOrReplyMatches implements Scorer
{
    use ReadsEnvelope;

    /**
     * @param  list<string>  $names
     */
    public function __construct(private array $names, private string $pattern) {}

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        if (($failed = $this->failedTurn($output)) instanceof ScorerResult) {
            return $failed;
        }

        $called = array_values(array_filter(
            array_column($this->toolCalls($output), 'name'),
            fn (string $name): bool => in_array($name, $this->names, true),
        ));

        if ($called === [] || preg_match($this->pattern, $this->replyText($output)) === 1) {
            return $this->pass($called === [] ? 'None of '.implode(', ', $this->names).' was called' : "The reply matches {$this->pattern}");
        }

        return $this->fail("{$called[0]} was called and the reply does not match {$this->pattern}");
    }
}
