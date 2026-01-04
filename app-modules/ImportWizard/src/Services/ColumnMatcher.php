<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Services;

use Illuminate\Support\Str;

final class ColumnMatcher
{
    /**
     * Normalize a string for matching by lowercasing and replacing
     * all separators (space, underscore, dash, dot) with underscore.
     */
    public function normalize(string $value): string
    {
        $normalized = preg_replace('/[\s_\-\.]+/', '_', trim($value));

        return Str::lower($normalized ?? $value);
    }

    /**
     * Find the first CSV header that matches any of the guesses.
     * Returns the original CSV header (preserving case) or null.
     *
     * Automatically expands guesses with plural/singular variants
     * to handle cases like "email" ↔ "emails", "phone" ↔ "phones".
     *
     * @param  array<string>  $csvHeaders
     * @param  array<string>  $guesses
     */
    public function findMatchingHeader(array $csvHeaders, array $guesses): ?string
    {
        $expandedGuesses = $this->expandWithPluralSingular($guesses);
        $normalizedGuesses = array_map($this->normalize(...), $expandedGuesses);

        foreach ($csvHeaders as $header) {
            $normalizedHeader = $this->normalize($header);

            if (in_array($normalizedHeader, $normalizedGuesses, true)) {
                return $header;
            }
        }

        return null;
    }

    /**
     * Expand guesses with plural and singular variants.
     *
     * @param  array<string>  $guesses
     * @return array<string>
     */
    private function expandWithPluralSingular(array $guesses): array
    {
        return collect($guesses)
            ->flatMap(fn (string $guess): array => [$guess, Str::plural($guess), Str::singular($guess)])
            ->unique()
            ->values()
            ->all();
    }
}
