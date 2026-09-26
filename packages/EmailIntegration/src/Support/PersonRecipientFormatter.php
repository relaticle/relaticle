<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use App\Models\People;
use Illuminate\Support\Collection;

final class PersonRecipientFormatter
{
    public static function displayName(People $person, ?string $email = null): string
    {
        $name = trim((string) $person->name);

        if (self::isUsableDisplayName($name)) {
            return $name;
        }

        if ($email !== null && self::validEmail($email) !== null) {
            return $email;
        }

        return $name !== '' ? $name : ($email ?? '');
    }

    public static function primaryEmailFromValue(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '' || str_starts_with($trimmed, '[')) {
                return null;
            }

            return self::validEmail($trimmed);
        }

        /** @var list<mixed> $items */
        $items = $value instanceof Collection
            ? $value->values()->all()
            : (is_array($value) ? $value : []);

        foreach ($items as $item) {
            if (! is_string($item)) {
                continue;
            }

            $email = self::validEmail(trim($item));

            if ($email !== null) {
                return $email;
            }
        }

        return null;
    }

    private static function isUsableDisplayName(string $name): bool
    {
        if ($name === '' || $name === 'Array') {
            return false;
        }

        return ! str_starts_with($name, '[') || ! str_ends_with($name, ']');
    }

    private static function validEmail(string $candidate): ?string
    {
        return filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false ? $candidate : null;
    }
}
