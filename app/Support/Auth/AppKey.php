<?php

declare(strict_types=1);

namespace App\Support\Auth;

use RuntimeException;

/**
 * The one decoder for `app.key`, shared by every HMAC/HKDF derivation in
 * this namespace. Fails closed: a missing or malformed key must stop the
 * caller rather than silently derive digests from a weaker value.
 */
final readonly class AppKey
{
    public static function decode(): string
    {
        $key = (string) config('app.key');

        throw_if($key === '', RuntimeException::class, 'Cannot derive a key: application key is not configured.');

        if (! str_starts_with($key, 'base64:')) {
            return $key;
        }

        $decoded = base64_decode(substr($key, 7), true);

        throw_if($decoded === false || $decoded === '', RuntimeException::class, 'Cannot derive a key: application key is not valid base64.');

        return $decoded;
    }
}
