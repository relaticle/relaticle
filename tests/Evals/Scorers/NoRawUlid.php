<?php

declare(strict_types=1);

namespace Tests\Evals\Scorers;

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use Tests\Evals\Scorers\Concerns\ReadsEnvelope;

final readonly class NoRawUlid implements Scorer
{
    use ReadsEnvelope;

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        $text = $this->text($output);
        $prose = preg_replace(['/\]\([^)]*\)/', '~https?://\S+~'], '', $text) ?? $text;

        if (preg_match('/\b[0-9a-hjkmnp-tv-z]{26}\b/i', $prose, $match) === 1) {
            return $this->fail("Raw record id in the reply: {$match[0]}");
        }

        return $this->pass('No raw record id in the reply');
    }
}
