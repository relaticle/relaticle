<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Enums\AuthMethod;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final readonly class AuthenticationSession
{
    private const string SESSION_KEY = 'auth.pending';

    private const int LIFETIME_MINUTES = 10;

    public static function begin(User $user, AuthMethod $method, ?string $credentialId, bool $remember): void
    {
        self::clear();

        session()->put(self::SESSION_KEY, [
            'id' => (string) Str::ulid(),
            'user_id' => (string) $user->getAuthIdentifier(),
            'method' => $method->value,
            'credential_id' => $credentialId,
            'fingerprint' => self::fingerprint($user, $method, $credentialId),
            'remember' => $remember,
            'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES)->getTimestamp(),
        ]);
    }

    /**
     * @return array{}|array{id: string, user_id: string, method: string, credential_id: string|null, fingerprint: string, remember: bool, expires_at: int}
     */
    public static function pending(): array
    {
        $pending = session()->get(self::SESSION_KEY);

        if (! is_array($pending)) {
            return [];
        }

        if (
            ! isset($pending['id'], $pending['user_id'], $pending['method'], $pending['fingerprint'], $pending['expires_at'])
            || ! is_string($pending['id'])
            || ! is_string($pending['user_id'])
            || ! is_string($pending['method'])
            || AuthMethod::tryFrom($pending['method']) === null
            || ! array_key_exists('credential_id', $pending)
            || (! is_string($pending['credential_id']) && $pending['credential_id'] !== null)
            || ! is_string($pending['fingerprint'])
            || ! array_key_exists('remember', $pending)
            || ! is_bool($pending['remember'])
            || ! is_int($pending['expires_at'])
        ) {
            return [];
        }

        return [
            'id' => $pending['id'],
            'user_id' => $pending['user_id'],
            'method' => $pending['method'],
            'credential_id' => $pending['credential_id'],
            'fingerprint' => $pending['fingerprint'],
            'remember' => $pending['remember'],
            'expires_at' => $pending['expires_at'],
        ];
    }

    public static function clear(): void
    {
        session()->forget([
            self::SESSION_KEY,
            'auth.password_confirmed_at',
            'login.id',
            'login.remember',
        ]);
    }

    public static function isValidFor(User $user): bool
    {
        $pending = self::pending();

        if ($pending === [] || $pending['expires_at'] <= now()->getTimestamp()) {
            return false;
        }

        $method = AuthMethod::tryFrom($pending['method']);

        return $method instanceof AuthMethod
            && hash_equals($pending['fingerprint'], self::fingerprint($user, $method, $pending['credential_id']));
    }

    /**
     * @return array{}|array{id: string, user_id: string, method: string, credential_id: string|null, fingerprint: string, remember: bool, expires_at: int}
     */
    public static function consume(User $user): array
    {
        $pending = self::pending();

        if ($pending === [] || ! self::isValidFor($user) || ! self::claim($pending['id'], $pending['expires_at'])) {
            return [];
        }

        return $pending;
    }

    private static function fingerprint(User $user, AuthMethod $method, ?string $credentialId): string
    {
        $key = hash_hkdf('sha256', self::decodedAppKey(), 32, 'relaticle.auth.state.v1');

        return hash_hmac('sha256', implode('|', [
            (string) $user->getAuthIdentifier(),
            $method->value,
            $credentialId ?? '',
            (string) $user->getRawOriginal('password'),
            (string) $user->getRawOriginal('two_factor_secret'),
            (string) $user->getRawOriginal('two_factor_confirmed_at'),
        ]), $key);
    }

    private static function claim(string $id, int $expiresAt): bool
    {
        return Cache::add(
            'auth.pending.consumed:'.$id,
            true,
            max(1, $expiresAt - now()->getTimestamp()),
        );
    }

    private static function decodedAppKey(): string
    {
        $key = (string) config('app.key');

        if (! str_starts_with($key, 'base64:')) {
            return $key;
        }

        $decoded = base64_decode(substr($key, 7), true);

        return is_string($decoded) ? $decoded : $key;
    }
}
