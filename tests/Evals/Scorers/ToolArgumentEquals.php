<?php

declare(strict_types=1);

namespace Tests\Evals\Scorers;

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use Tests\Evals\Scorers\Concerns\ReadsEnvelope;

final readonly class ToolArgumentEquals implements Scorer
{
    use ReadsEnvelope;

    public function __construct(
        private string $tool,
        private string $path,
        private string $expected,
        private bool $prefix = false,
    ) {}

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        if (($failed = $this->failedTurn($output)) instanceof ScorerResult) {
            return $failed;
        }

        $seen = [];

        foreach ($this->toolCalls($output) as $call) {
            if ($call['name'] !== $this->tool) {
                continue;
            }

            foreach ($this->valuesAt($call['arguments'], explode('.', $this->path)) as $value) {
                $seen[] = $value;

                if ($this->matches($value)) {
                    return $this->pass("{$this->tool} {$this->path} is {$value}");
                }
            }
        }

        return $this->fail("{$this->tool} {$this->path} was expected to ".($this->prefix ? 'start with ' : 'be ').$this->expected.', saw '.($seen === [] ? 'nothing' : implode(', ', $seen)));
    }

    private function matches(string $value): bool
    {
        return $this->prefix ? str_starts_with($value, $this->expected) : $value === $this->expected;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $segments
     * @return list<string>
     */
    private function valuesAt(array $node, array $segments): array
    {
        $segment = array_shift($segments);

        $children = $segment === '*' ? array_values($node) : (array_key_exists($segment, $node) ? [$node[$segment]] : []);
        $values = [];

        foreach ($children as $child) {
            if ($segments === []) {
                $values[] = is_scalar($child) ? (string) $child : (string) json_encode($child);

                continue;
            }

            if (is_array($child)) {
                $values = [...$values, ...$this->valuesAt($child, $segments)];
            }
        }

        return $values;
    }
}
