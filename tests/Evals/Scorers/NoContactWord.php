<?php

declare(strict_types=1);

namespace Tests\Evals\Scorers;

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use Tests\Evals\Scorers\Concerns\ReadsEnvelope;

final readonly class NoContactWord implements Scorer
{
    use ReadsEnvelope;

    private const array ALLOWED_SPANS = [
        '/\bpoint of contact\b/i',
        '/\bcontact(?:ed|ing)\b/i',
        '/\bcontact(?:ed|ing)?\s+(?:them|him|her|me|us)\b/i',
        '/(?:(?i:\b(?:to|will|and|or|can|should)\s+|\'ll\s+|\'d\s+)|(?m:^)|[.!?:]\s+)(?i:contact(?:ed|ing)?)\s+(?:(?i:you|the\s+\w+)|[A-Z]\w+)/u',
    ];

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        if (($failed = $this->failedTurn($output)) instanceof ScorerResult) {
            return $failed;
        }

        $text = $this->text($output);
        $prose = preg_replace(self::ALLOWED_SPANS, '', $text) ?? $text;

        if (preg_match('/\bcontact(?:s)?\b/i', $prose, $match) !== 1) {
            return $this->pass('The reply never says contact');
        }

        if (preg_match('/\bcontacts?\b/i', $expected ?? $input) === 1) {
            return $this->pass('The user said contact first');
        }

        return $this->fail("The reply says {$match[0]} where the user did not");
    }
}
