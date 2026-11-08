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
        if (($failed = $this->failedTurn($output)) instanceof ScorerResult) {
            return $failed;
        }

        $text = $this->text($output);

        if (preg_match('/^\s*\|.*\|\s*$\n^\s*\|[\s:|-]+\|/m', $text, $match) === 1 || preg_match('/^[^\n|]+\|[^\n]+\n[-:| ]+\|[-:| ]+$/m', $text, $match) === 1) {
            return $this->fail('Markdown table in the reply: '.trim($match[0]));
        }

        return $this->pass('No markdown table in the reply');
    }
}
