<?php

declare(strict_types=1);

namespace App\Support;

final readonly class PhoneSearch
{
    public const string ELEMENT_CONDITION = "(cf.type = 'phone' and json_typeof(cfv.json_value) = 'array' and exists (select 1 from json_array_elements_text(cfv.json_value) as elem(val) where regexp_replace(elem.val, '\\D', '', 'g') like ?))";

    private const int MIN_DIGITS = 7;

    public static function pattern(string $query): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $query);

        return strlen($digits) >= self::MIN_DIGITS ? "%{$digits}%" : null;
    }
}
