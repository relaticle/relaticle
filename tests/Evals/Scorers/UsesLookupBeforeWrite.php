<?php

declare(strict_types=1);

namespace Tests\Evals\Scorers;

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use Tests\Evals\Scorers\Concerns\ReadsEnvelope;

final readonly class UsesLookupBeforeWrite implements Scorer
{
    use ReadsEnvelope;

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        $heavyRead = null;

        foreach ($this->toolCalls($output) as $call) {
            if (preg_match('/^(Create|Update|Delete|Set|Add|Send|Invite)/', $call['name']) === 1 && $heavyRead !== null) {
                return $this->fail("{$call['name']} follows {$heavyRead} without lookup true");
            }

            if (preg_match('/^(List|Get)/', $call['name']) === 1 && ($call['arguments']['lookup'] ?? null) !== true) {
                $heavyRead ??= $call['name'];
            }
        }

        return $this->pass('Every read before a write used lookup true');
    }
}
