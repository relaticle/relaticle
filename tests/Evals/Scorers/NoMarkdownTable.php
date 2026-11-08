<?php

declare(strict_types=1);

namespace Tests\Evals\Scorers;

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use Tests\Evals\Scorers\Concerns\ReadsEnvelope;

final readonly class NoMarkdownTable implements Scorer
{
    use ReadsEnvelope;

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        if (preg_match('/^\s*\|.*\|\s*$\n^\s*\|[\s:|-]+\|/m', $this->text($output), $match) === 1) {
            return $this->fail('Markdown table in the reply: '.trim($match[0]));
        }

        return $this->pass('No markdown table in the reply');
    }
}
