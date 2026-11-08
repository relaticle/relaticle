<?php

declare(strict_types=1);

namespace Tests\Evals\Scorers;

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use Tests\Evals\Scorers\Concerns\ReadsEnvelope;

final readonly class NoDuplicateRowSet implements Scorer
{
    use ReadsEnvelope;

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        $previous = array_map($this->key(...), $this->rowIdSets((string) $expected));

        foreach ($this->rowIdSets($output) as $ids) {
            if ($ids !== [] && in_array($this->key($ids), $previous, true)) {
                return $this->fail('Repeated the row set of the previous turn: '.$this->key($ids));
            }
        }

        return $this->pass('No row set repeats the previous turn');
    }

    /**
     * @param  list<string>  $ids
     */
    private function key(array $ids): string
    {
        sort($ids);

        return implode(',', $ids);
    }

    /**
     * @return list<list<string>>
     */
    private function rowIdSets(string $envelope): array
    {
        $sets = $this->envelope($envelope)['tool_result_row_ids'] ?? [];

        return is_array($sets) ? array_values(array_filter($sets, is_array(...))) : [];
    }
}
