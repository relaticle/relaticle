<?php

declare(strict_types=1);

namespace App\Onboarding;

final readonly class OptionLabels
{
    /**
     * The English label itself, then the label of its lang key in every available language.
     *
     * @param  list<string>  $langKeys
     * @return list<string>
     */
    public static function inEveryLocale(array $langKeys, string $english): array
    {
        foreach ($langKeys as $key) {
            if (__($key, [], 'en') === $english) {
                return array_values(array_unique([
                    $english,
                    ...array_map(fn (string $locale): string => __($key, [], $locale), config('app.available_locales')),
                ]));
            }
        }

        return [$english];
    }
}
