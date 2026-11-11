<?php

declare(strict_types=1);

namespace App\Filament\Tables\FilterBar;

// Filament stores one `column:direction`, so a list joins those with commas and a single sort reads as before.
final readonly class SortList
{
    /**
     * @return array<string, 'asc'|'desc'>
     */
    public static function parse(?string $value): array
    {
        $sorts = [];

        foreach (explode(',', (string) $value) as $segment) {
            [$column, $direction] = array_pad(explode(':', trim($segment), 2), 2, 'asc');

            if ($column !== '' && ! isset($sorts[$column]) && in_array($direction, ['asc', 'desc'], true)) {
                $sorts[$column] = $direction;
            }
        }

        return $sorts;
    }

    /**
     * @param  array<string, string>  $sorts
     */
    public static function format(array $sorts): ?string
    {
        $segments = array_map(fn (string $column, string $direction): string => "{$column}:{$direction}", array_keys($sorts), $sorts);

        return $segments === [] ? null : implode(',', $segments);
    }
}
