<?php

declare(strict_types=1);

namespace Tests\Evals\Support;

use RuntimeException;

final class EvalModels
{
    public const array ALIASES = [
        'gpt-5.5' => ['gpt-5.5', 'gpt-6-luna', 'gpt-5.5-2026-04-23'],
    ];

    public static function assertServed(string $requested, string $served): void
    {
        if ($served === '' || $served === $requested || in_array($served, self::ALIASES[$requested] ?? [], true)) {
            return;
        }

        throw new RuntimeException("Asked for {$requested}, served by {$served}");
    }
}
