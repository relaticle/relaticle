<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Enums\AuthMethod;
use App\Models\User;
use App\Models\UserSocialAccount;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Passkeys\Passkey;

final readonly class AuthenticationSession
{
    private const string SESSION_KEY = 'auth.pending';

    private const string COMPLETED_KEY = 'auth.completed';

    private const string OPERATION_KEY = 'auth.operation';

    private const string ATTEMPT_KEY = 'auth.attempt';

    private const string PROVIDER_CONFIRM_KEY = 'auth.confirm.provider_grant';

    private const string LINK_SUGGESTION_KEY = 'auth.link_suggestion';

    private const string RESUME_KEY = 'auth.confirm.resume';

    private const int LIFETIME_MINUTES = 10;

    private const int OPERATION_LIFETIME_MINUTES = 15;

    private const int LINK_SUGGESTION_LIFETIME_MINUTES = 10;

    /**
     * The only sensitive operations that may consume a scoped, one-use identity
     * confirmation grant. A caller for an operation outside this list has no
     * legitimate reason to mint one.
     *
     * @var list<string>
     */
    private const array ALLOWED_OPERATIONS = [
        'add_passkey',
        'delete_passkey',
        'set_password',
        'link_provider',
        'unlink_provider',
        'change_email',
        'email_sign_in',
        'manage_mfa',
    ];

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
            self::COMPLETED_KEY,
            self::OPERATION_KEY,
            self::ATTEMPT_KEY,
            self::PROVIDER_CONFIRM_KEY,
            self::RESUME_KEY,
            'auth.password_confirmed_at',
            'login.id',
            'login.remember',
        ]);
    }

    /**
     * Mint a server-bound attempt for one allowlisted sensitive operation. The
     * returned id is safe to hand to the client (e.g. as a hidden form field):
     * it names the grant but cannot forge one, since every check below re-derives
     * the fingerprint from server-side state the client cannot see or set.
     */
    public static function startOperation(User $user, string $operation, ?string $targetId): string
    {
        throw_unless(in_array($operation, self::ALLOWED_OPERATIONS, true), InvalidArgumentException::class, "Unsupported identity-confirmation operation: {$operation}");

        $id = (string) Str::ulid();

        session()->put(self::OPERATION_KEY, [
            'id' => $id,
            'user_id' => (string) $user->getAuthIdentifier(),
            'operation' => $operation,
            'target_id' => $targetId,
            'fingerprint' => self::operationFingerprint($user, $operation, $targetId),
            'expires_at' => now()->addMinutes(self::OPERATION_LIFETIME_MINUTES)->getTimestamp(),
        ]);

        return $id;
    }

    /**
     * @return array{}|array{id: string, user_id: string, operation: string, target_id: string|null, fingerprint: string, expires_at: int, proven: bool}
     */
    public static function pendingOperation(): array
    {
        $pending = session()->get(self::OPERATION_KEY);

        if (! is_array($pending)) {
            return [];
        }

        if (
            ! isset($pending['id'], $pending['user_id'], $pending['operation'], $pending['fingerprint'], $pending['expires_at'])
            || ! is_string($pending['id'])
            || ! is_string($pending['user_id'])
            || ! is_string($pending['operation'])
            || ! in_array($pending['operation'], self::ALLOWED_OPERATIONS, true)
            || ! array_key_exists('target_id', $pending)
            || (! is_string($pending['target_id']) && $pending['target_id'] !== null)
            || ! is_string($pending['fingerprint'])
            || ! is_int($pending['expires_at'])
        ) {
            return [];
        }

        return [
            'id' => $pending['id'],
            'user_id' => $pending['user_id'],
            'operation' => $pending['operation'],
            'target_id' => $pending['target_id'],
            'fingerprint' => $pending['fingerprint'],
            'expires_at' => $pending['expires_at'],
            'proven' => ($pending['proven'] ?? false) === true,
        ];
    }

    public static function cancelOperation(string $id): void
    {
        if ((self::pendingOperation()['id'] ?? null) === $id) {
            session()->forget(self::OPERATION_KEY);
        }
    }

    /**
     * Mark a minted grant as identity-proven. Proof alone never spends the grant:
     * the actual mutation (same request or a later one, e.g. the browser's own
     * WebAuthn registration POST) must still call requireOperation()/
     * consumeOperation() at the point of the write, or the proof is worthless.
     */
    public static function proveOperation(User $user, string $operation, ?string $targetId): void
    {
        $pending = self::pendingOperation();

        if (! self::operationMatches($pending, $user, $operation, $targetId)) {
            throw ValidationException::withMessages([
                'identity' => [__('auth.confirm.required')],
            ]);
        }

        $pending['proven'] = true;

        session()->put(self::OPERATION_KEY, $pending);
    }

    /**
     * Reject a missing, expired, foreign, retargeted, tampered, or unproven grant.
     * A grant minted before a password or MFA change fails the fingerprint check
     * and counts as stale. Passkey state is not part of the fingerprint.
     * This is the actual authorization check for a mutation: call it at
     * the point of the write, not only at the point identity was proven.
     */
    public static function requireOperation(User $user, string $operation, ?string $targetId): void
    {
        $pending = self::pendingOperation();

        if ($pending === [] || ! self::operationMatches($pending, $user, $operation, $targetId) || ! $pending['proven']) {
            throw ValidationException::withMessages([
                'identity' => [__('auth.confirm.required')],
            ]);
        }
    }

    /**
     * Atomically consume a proven grant so it cannot authorize a second write.
     * Call this at the moment the mutation actually happens, never earlier.
     */
    public static function consumeOperation(User $user, string $operation, ?string $targetId): void
    {
        self::requireOperation($user, $operation, $targetId);

        $pending = self::pendingOperation();

        if ($pending === [] || ! self::claim($pending['id'], $pending['expires_at'])) {
            throw ValidationException::withMessages([
                'identity' => [__('auth.confirm.required')],
            ]);
        }

        session()->forget(self::OPERATION_KEY);
    }

    /**
     * The provider round trip is a full page load, so the Livewire component
     * holding the modal is destroyed before the user returns.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function rememberResumableAction(User $user, string $component, string $action, array $arguments, ?string $grantId): void
    {
        session()->put(self::RESUME_KEY, [
            'user_id' => (string) $user->getAuthIdentifier(),
            'component' => $component,
            'action' => $action,
            'arguments' => $arguments,
            'grant_id' => $grantId,
            'expires_at' => now()->addMinutes(self::OPERATION_LIFETIME_MINUTES)->getTimestamp(),
        ]);
    }

    /**
     * One-time read, for the named component only, and only once the proof it
     * was waiting on landed.
     *
     * @return array{action: string, arguments: array<string, mixed>}|null
     */
    public static function pullResumableAction(User $user, string $component): ?array
    {
        $resume = session()->get(self::RESUME_KEY);

        if (
            ! is_array($resume)
            || ! isset($resume['user_id'], $resume['component'], $resume['action'], $resume['arguments'], $resume['expires_at'])
            || ! is_string($resume['user_id'])
            || ! is_string($resume['component'])
            || ! is_string($resume['action'])
            || ! is_array($resume['arguments'])
            || ! is_int($resume['expires_at'])
            || ! array_key_exists('grant_id', $resume)
            || (! is_string($resume['grant_id']) && $resume['grant_id'] !== null)
        ) {
            return null;
        }

        // A sibling component on the same page must not consume a descriptor
        // addressed elsewhere; once it is ours it is spent either way.
        if ($resume['user_id'] !== (string) $user->getAuthIdentifier() || $resume['component'] !== $component) {
            return null;
        }

        session()->forget(self::RESUME_KEY);

        if ($resume['expires_at'] <= now()->getTimestamp() || ! self::resumeProofSatisfied($resume['grant_id'])) {
            return null;
        }

        /** @var array<string, mixed> $arguments */
        $arguments = $resume['arguments'];

        return ['action' => $resume['action'], 'arguments' => $arguments];
    }

    /**
     * Whether this exact grant is still the one in the slot and has been proven.
     * A forged or superseded id must never fall through to the generic window.
     */
    public static function operationProven(string $grantId): bool
    {
        $pending = self::pendingOperation();

        return $pending !== [] && $pending['id'] === $grantId && $pending['proven'];
    }

    private static function resumeProofSatisfied(?string $grantId): bool
    {
        return $grantId === null
            ? IdentityConfirmation::confirmedRecently()
            : self::operationProven($grantId);
    }

    /**
     * Bind the operation grant in flight to a provider-confirmation redirect
     * about to leave the app. The single OPERATION_KEY slot can be overwritten
     * by an unrelated modal opened in another tab while the OAuth round trip is
     * in progress; the callback must trust only the grant it stashed here, never
     * whatever happens to occupy the slot when the user returns.
     */
    public static function stashOperationForProviderConfirm(): void
    {
        $pending = self::pendingOperation();

        session()->put(self::PROVIDER_CONFIRM_KEY, $pending['id'] ?? null);
    }

    /**
     * One-time read of the grant id stashed before a provider-confirmation
     * redirect. Returns null if none was stashed, already consumed, or the
     * request never went through stashOperationForProviderConfirm().
     */
    public static function consumeStashedProviderOperationGrantId(): ?string
    {
        $id = session()->pull(self::PROVIDER_CONFIRM_KEY);

        return is_string($id) ? $id : null;
    }

    /**
     * Rebind an operation grant minted before the provider identity it targets
     * was known (link_provider starts with a null target, since the provider
     * assigns that id only once the OAuth round trip completes) to the identity
     * that round trip just proved, marking it proven in the same step. Only the
     * exact grant id stashed before that redirect may be rebound, and only once:
     * an already-targeted grant is refused rather than retargeted a second time.
     * Also re-derives the fingerprint over the mint-time credentials (target
     * null) so a grant does not outlive a password or MFA change.
     */
    public static function bindOperationTarget(User $user, string $operation, string $grantId, string $targetId): void
    {
        $pending = self::pendingOperation();

        if (
            $pending === []
            || $pending['id'] !== $grantId
            || $pending['user_id'] !== (string) $user->getAuthIdentifier()
            || $pending['operation'] !== $operation
            || $pending['target_id'] !== null
            || $pending['expires_at'] <= now()->getTimestamp()
            || ! hash_equals($pending['fingerprint'], self::operationFingerprint($user, $operation, null))
        ) {
            throw ValidationException::withMessages([
                'identity' => [__('auth.confirm.required')],
            ]);
        }

        session()->put(self::OPERATION_KEY, [
            'id' => $pending['id'],
            'user_id' => $pending['user_id'],
            'operation' => $pending['operation'],
            'target_id' => $targetId,
            'fingerprint' => self::operationFingerprint($user, $operation, $targetId),
            'expires_at' => $pending['expires_at'],
            'proven' => true,
        ]);
    }

    /**
     * Record a candidate provider identity for a later, explicit link. This is
     * a UI hint only: a matching email during a guest OAuth callback is never
     * proof of ownership, so it grants no capability by itself. Establishing
     * the link still requires the full link_provider flow (both proofs).
     */
    public static function suggestLink(string $providerName, string $providerId, string $email): void
    {
        session()->put(self::LINK_SUGGESTION_KEY, [
            'provider' => $providerName,
            'provider_id' => $providerId,
            'email' => $email,
            'expires_at' => now()->addMinutes(self::LINK_SUGGESTION_LIFETIME_MINUTES)->getTimestamp(),
        ]);
    }

    /**
     * @return array{}|array{provider: string, provider_id: string, email: string, expires_at: int}
     */
    public static function linkSuggestion(): array
    {
        $suggestion = session()->get(self::LINK_SUGGESTION_KEY);

        if (
            ! is_array($suggestion)
            || ! isset($suggestion['provider'], $suggestion['provider_id'], $suggestion['email'], $suggestion['expires_at'])
            || ! is_string($suggestion['provider'])
            || ! is_string($suggestion['provider_id'])
            || ! is_string($suggestion['email'])
            || ! is_int($suggestion['expires_at'])
            || $suggestion['expires_at'] <= now()->getTimestamp()
        ) {
            return [];
        }

        return [
            'provider' => $suggestion['provider'],
            'provider_id' => $suggestion['provider_id'],
            'email' => $suggestion['email'],
            'expires_at' => $suggestion['expires_at'],
        ];
    }

    /**
     * @param  array{}|array{id: string, user_id: string, operation: string, target_id: string|null, fingerprint: string, expires_at: int, proven: bool}  $pending
     */
    private static function operationMatches(array $pending, User $user, string $operation, ?string $targetId): bool
    {
        if ($pending === [] || $pending['expires_at'] <= now()->getTimestamp()) {
            return false;
        }

        if ($pending['user_id'] !== (string) $user->getAuthIdentifier() || $pending['operation'] !== $operation || $pending['target_id'] !== $targetId) {
            return false;
        }

        return hash_equals($pending['fingerprint'], self::operationFingerprint($user, $operation, $targetId));
    }

    private static function operationFingerprint(User $user, string $operation, ?string $targetId): string
    {
        $key = hash_hkdf('sha256', self::decodedAppKey(), 32, 'relaticle.auth.operation.v1');

        return hash_hmac('sha256', implode('|', [
            (string) $user->getAuthIdentifier(),
            $operation,
            $targetId ?? '',
            (string) $user->getRawOriginal('password'),
            (string) $user->getRawOriginal('two_factor_secret'),
            (string) $user->getRawOriginal('two_factor_confirmed_at'),
        ]), $key);
    }

    /**
     * Mint a server-bound genesis marker for an always-confirm action that has no
     * allowlisted operation of its own (account deletion, session logout). The
     * client only ever sees the opaque id; the timestamp it names never leaves
     * the server, so it cannot be lowered to make a stale confirmation look fresh.
     */
    public static function beginAttempt(): string
    {
        $id = (string) Str::ulid();

        session()->put(self::ATTEMPT_KEY, [
            'id' => $id,
            'started_at' => now()->getTimestamp(),
        ]);

        return $id;
    }

    /**
     * The attempt's genesis timestamp, or PHP_INT_MAX for a missing or forged id
     * so a caller comparing "confirmed after this started" always fails safe.
     */
    public static function attemptStartedAt(string $id): int
    {
        $attempt = session()->get(self::ATTEMPT_KEY);

        if (! is_array($attempt) || ($attempt['id'] ?? null) !== $id || ! is_int($attempt['started_at'] ?? null)) {
            return PHP_INT_MAX;
        }

        return $attempt['started_at'];
    }

    public static function isValidFor(User $user): bool
    {
        $pending = self::pending();

        if ($pending === [] || $pending['expires_at'] <= now()->getTimestamp()) {
            return false;
        }

        $method = AuthMethod::tryFrom($pending['method']);

        if (! $method instanceof AuthMethod || ! hash_equals($pending['fingerprint'], self::fingerprint($user, $method, $pending['credential_id']))) {
            return false;
        }

        return self::credentialStillOwned($user, $method, $pending['credential_id']);
    }

    /**
     * Revoking the passkey or unlinking the provider mid-challenge must not let a
     * stale pending proof, minted before the revocation, still complete login.
     */
    private static function credentialStillOwned(User $user, AuthMethod $method, ?string $credentialId): bool
    {
        if ($credentialId === null) {
            return true;
        }

        return match ($method) {
            AuthMethod::PASSKEY => is_numeric($credentialId) && Passkey::query()
                ->whereKey((int) $credentialId)
                ->where('user_id', $user->getKey())
                ->exists(),
            AuthMethod::GOOGLE, AuthMethod::MICROSOFT => UserSocialAccount::query()
                ->whereKey($credentialId)
                ->where('user_id', $user->getKey())
                ->where('provider_name', $method->value)
                ->exists(),
            AuthMethod::PASSWORD, AuthMethod::EMAIL, AuthMethod::SIGNUP, AuthMethod::REMEMBERED => true,
        };
    }

    // Read viaRemember() before logoutCurrentDevice(), which clears the recaller
    // this reports on. Drops only this device, keeping other devices remembered.
    public static function suspendRemembered(): bool
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        $viaRemember = $guard->viaRemember();

        $guard->logoutCurrentDevice();

        return $viaRemember;
    }

    public static function completeFor(User $user): bool
    {
        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return true;
        }

        $marker = session()->get(self::COMPLETED_KEY);

        if (
            ! is_array($marker)
            || ! isset($marker['user_id'], $marker['fingerprint'])
            || ! is_string($marker['user_id'])
            || ! is_string($marker['fingerprint'])
            || $marker['user_id'] !== (string) $user->getAuthIdentifier()
        ) {
            return false;
        }

        return hash_equals($marker['fingerprint'], self::completionFingerprint($user));
    }

    public static function markComplete(User $user): void
    {
        session()->put(self::COMPLETED_KEY, [
            'user_id' => (string) $user->getAuthIdentifier(),
            'fingerprint' => self::completionFingerprint($user),
        ]);
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

    private static function completionFingerprint(User $user): string
    {
        $key = hash_hkdf('sha256', self::decodedAppKey(), 32, 'relaticle.auth.completion.v1');

        return hash_hmac('sha256', implode('|', [
            (string) $user->getAuthIdentifier(),
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
        return AppKey::decode();
    }
}
