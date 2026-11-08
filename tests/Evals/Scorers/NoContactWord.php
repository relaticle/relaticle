<?php

declare(strict_types=1);

namespace Tests\Evals\Scorers;

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use Tests\Evals\Scorers\Concerns\ReadsEnvelope;

final readonly class NoContactWord implements Scorer
{
    use ReadsEnvelope;

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        if (preg_match('/\bcontacts?\b/i', $this->text($output), $match) !== 1) {
            return $this->pass('The reply never says contact');
        }

        $word = preg_quote($match[0], '/');

        if (preg_match("/\\b{$word}\\b/i", (string) $expected) === 1) {
            return $this->pass("The reply repeats the user's own word: {$match[0]}");
        }

        return $this->fail("The reply says {$match[0]} where the user did not");
    }
}
