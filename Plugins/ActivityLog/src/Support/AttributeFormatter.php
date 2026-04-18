<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Support;

use BackedEnum;
use Illuminate\Support\Carbon;
use Stringable;

final class AttributeFormatter
{
    public static function format(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof Carbon) {
            return $value->toDayDateTimeString();
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '' : $encoded;
    }
}
