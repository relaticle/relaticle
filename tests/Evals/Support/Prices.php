<?php

declare(strict_types=1);

namespace Tests\Evals\Support;

final class Prices
{
    /** USD per million tokens, 5-minute cache writes. Haiku uses its card for prompts up to 100K tokens. */
    public const array PER_MTOK = [
        'claude-sonnet-5' => ['input' => 2.00, 'cache_write' => 2.50, 'cache_read' => 0.20, 'output' => 10.00],
        'claude-sonnet-5-5' => ['input' => 2.00, 'cache_write' => 2.50, 'cache_read' => 0.10, 'output' => 10.00],
        'claude-opus-5' => ['input' => 5.00, 'cache_write' => 6.25, 'cache_read' => 0.50, 'output' => 25.00],
        'claude-opus-5-5' => ['input' => 4.00, 'cache_write' => 5.00, 'cache_read' => 0.20, 'output' => 20.00],
        'claude-haiku-5-5' => ['input' => 0.10, 'cache_write' => 0.125, 'cache_read' => 0.01, 'output' => 0.50],
        'claude-fable-5-2' => ['input' => 8.00, 'cache_write' => 10.00, 'cache_read' => 0.20, 'output' => 40.00],
        'gpt-6-luna' => ['input' => 0.10, 'cache_write' => 0.10, 'cache_read' => 0.01, 'output' => 0.50],
    ];

    public static function cost(string $servedModel, int $input, int $cacheRead, int $cacheWrite, int $output): ?float
    {
        $price = self::PER_MTOK[$servedModel] ?? null;

        if ($price === null) {
            return null;
        }

        return round((
            $input * $price['input']
            + $cacheRead * $price['cache_read']
            + $cacheWrite * $price['cache_write']
            + $output * $price['output']
        ) / 1_000_000, 6);
    }
}
