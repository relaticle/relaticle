<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\EmailChallengePurpose;
use App\Jobs\Email\SendEmailChallenge;
use App\Models\EmailChallenge;
use App\Models\User;
use App\Support\Auth\AppKey;
use App\Support\EmailAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Issues a fresh email code, or replaces the current one in an active flow.
 * Only authenticated email verification is enabled; every other purpose refuses until its own task wires a caller.
 */
final readonly class RequestEmailChallenge
{
    private const string SESSION_PREFIX = 'auth.email_challenge.flow.';

    public function execute(EmailChallengePurpose $purpose, string $email, ?User $user, ?string $operationId, string $ip): EmailChallenge
    {
        $this->assertEnabled($purpose, $operationId);
        $this->assertKeyAvailable();

        $canonicalEmail = EmailAddress::canonicalize($this->resolveEmail($purpose, $email, $user));

        $this->guardSendLimits($purpose, $canonicalEmail);
        $this->guardIpLimit($ip);

        return DB::transaction(function () use ($purpose, $canonicalEmail, $user): EmailChallenge {
            if ($user instanceof User) {
                User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            }

            $active = $this->lockActiveGeneration($purpose, $canonicalEmail);

            [$challenge, $code] = $active === null
                ? $this->issue($purpose, $canonicalEmail, $user)
                : $this->resend($active['root'], $active['current']);

            dispatch(new SendEmailChallenge($challenge->id, $code))->afterCommit();

            return $challenge;
        });
    }

    /**
     * Only VERIFY_EMAIL has a caller today, and only with no operation id:
     * it never consumes a grant, so a submitted one is refused, not ignored.
     */
    private function assertEnabled(EmailChallengePurpose $purpose, ?string $operationId): void
    {
        if ($purpose === EmailChallengePurpose::VERIFY_EMAIL && $operationId === null) {
            return;
        }

        throw ValidationException::withMessages([
            'purpose' => [__('auth.email_code.unavailable')],
        ]);
    }

    /**
     * Probes the key outside the transaction so a derivation failure never
     * shares a catch with a genuine database error.
     */
    private function assertKeyAvailable(): void
    {
        try {
            AppKey::decode();
        } catch (RuntimeException) {
            throw ValidationException::withMessages([
                'purpose' => [__('auth.email_code.unavailable')],
            ]);
        }
    }

    /**
     * Verification always reuses the account's own email; a submitted
     * replacement is never trusted here.
     */
    private function resolveEmail(EmailChallengePurpose $purpose, string $email, ?User $user): string
    {
        if ($purpose === EmailChallengePurpose::VERIFY_EMAIL) {
            if (! $user instanceof User) {
                throw ValidationException::withMessages([
                    'purpose' => [__('auth.email_code.unavailable')],
                ]);
            }

            return $user->email;
        }

        return $email;
    }

    /**
     * One atomic increment per key, checked against its own return value.
     * Fails closed on a limiter outage.
     */
    private function guardSendLimits(EmailChallengePurpose $purpose, string $canonicalEmail): void
    {
        $cooldownKey = 'email-challenge:cooldown:'.$purpose->value.':'.$canonicalEmail;
        $windowKey = 'email-challenge:window:'.$purpose->value.':'.$canonicalEmail;
        $windowMaxAttempts = (int) config('auth.email_codes.window_max_attempts', 3);

        try {
            $cooldownHits = RateLimiter::hit($cooldownKey, (int) config('auth.email_codes.cooldown_seconds', 60));
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'purpose' => [__('auth.email_code.unavailable')],
            ]);
        }

        if ($cooldownHits > 1) {
            $seconds = RateLimiter::availableIn($cooldownKey);

            throw ValidationException::withMessages([
                'purpose' => [trans_choice('auth.email_code.cooldown', $seconds, ['seconds' => $seconds])],
            ]);
        }

        try {
            $windowHits = RateLimiter::hit($windowKey, (int) config('auth.email_codes.window_seconds', 900));
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'purpose' => [__('auth.email_code.unavailable')],
            ]);
        }

        if ($windowHits > $windowMaxAttempts) {
            $minutes = (int) ceil(RateLimiter::availableIn($windowKey) / 60);

            throw ValidationException::withMessages([
                'purpose' => [trans_choice('auth.email_code.throttled', $minutes, ['minutes' => $minutes])],
            ]);
        }
    }

    /**
     * Shared across every purpose and target, keyed only by source IP.
     * One atomic increment, checked against its own return value.
     */
    private function guardIpLimit(string $ip): void
    {
        if ($ip === '') {
            throw ValidationException::withMessages([
                'purpose' => [__('auth.email_code.unavailable')],
            ]);
        }

        $key = 'email-challenge:ip:'.$ip;
        $maxAttempts = (int) config('auth.email_codes.ip_max_attempts', 20);

        try {
            $hits = RateLimiter::hit($key, (int) config('auth.email_codes.ip_decay_seconds', 3600));
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'purpose' => [__('auth.email_code.unavailable')],
            ]);
        }

        if ($hits > $maxAttempts) {
            throw ValidationException::withMessages([
                'purpose' => [__('auth.throttle_long')],
            ]);
        }
    }

    /**
     * The active flow for this exact browser session, never a blind lookup
     * by user or email: a different session always starts its own flow.
     *
     * @return array{root: EmailChallenge, current: EmailChallenge}|null
     */
    private function lockActiveGeneration(EmailChallengePurpose $purpose, string $canonicalEmail): ?array
    {
        $marker = $this->sessionFlow($purpose, $canonicalEmail);

        if ($marker === null) {
            return null;
        }

        $root = EmailChallenge::query()->whereKey($marker['flow_id'])->lockForUpdate()->first();

        if (! $root instanceof EmailChallenge || ! hash_equals($root->flow_digest, EmailChallenge::hashFlowSecret($marker['secret']))) {
            return null;
        }

        $current = EmailChallenge::query()
            ->where('flow_id', $root->id)
            ->orderByDesc('generation')
            ->lockForUpdate()
            ->first();

        if (! $current instanceof EmailChallenge || $current->isTerminal()) {
            return null;
        }

        return ['root' => $root, 'current' => $current];
    }

    /**
     * @return array{flow_id: string, secret: string, target: string}|null
     */
    private function sessionFlow(EmailChallengePurpose $purpose, string $canonicalEmail): ?array
    {
        $marker = session()->get(self::SESSION_PREFIX.$purpose->value);

        if (
            ! is_array($marker)
            || ! isset($marker['flow_id'], $marker['secret'], $marker['target'])
            || ! is_string($marker['flow_id'])
            || ! is_string($marker['secret'])
            || ! is_string($marker['target'])
            || $marker['target'] !== $canonicalEmail
        ) {
            return null;
        }

        return ['flow_id' => $marker['flow_id'], 'secret' => $marker['secret'], 'target' => $marker['target']];
    }

    private function rememberFlow(EmailChallengePurpose $purpose, string $flowId, string $secret, string $canonicalEmail): void
    {
        session()->put(self::SESSION_PREFIX.$purpose->value, [
            'flow_id' => $flowId,
            'secret' => $secret,
            'target' => $canonicalEmail,
        ]);
    }

    /**
     * @return array{0: EmailChallenge, 1: string}
     */
    private function issue(EmailChallengePurpose $purpose, string $canonicalEmail, ?User $user): array
    {
        $id = (string) Str::ulid();
        $code = $this->generateCode();
        // Kept only in session, never in the database: its digest alone
        // proves this browser owns the flow.
        $secret = Str::random(40);

        $challenge = EmailChallenge::query()->forceCreate([
            'id' => $id,
            'flow_id' => $id,
            'generation' => 1,
            'purpose' => $purpose,
            'email' => $canonicalEmail,
            'user_id' => $user?->getKey(),
            'flow_digest' => EmailChallenge::hashFlowSecret($secret),
            'code_digest' => EmailChallenge::hashCode($id, $purpose, $code),
            'state_fingerprint' => $user instanceof User ? EmailChallenge::stateFingerprint($user) : null,
            'operation_id' => null,
            'expires_at' => now()->addMinutes($purpose->lifetimeMinutes()),
            'failed_attempts' => 0,
            'created_at' => now(),
        ]);

        $this->rememberFlow($purpose, $id, $secret, $canonicalEmail);

        return [$challenge, $code];
    }

    /**
     * @return array{0: EmailChallenge, 1: string}
     */
    private function resend(EmailChallenge $root, EmailChallenge $current): array
    {
        $current->forceFill([
            'invalidated_at' => now(),
            'invalidation_reason' => 'superseded',
        ])->save();

        $id = (string) Str::ulid();
        $code = $this->generateCode();

        $next = EmailChallenge::query()->forceCreate([
            'id' => $id,
            'flow_id' => $root->id,
            'generation' => $current->generation + 1,
            'purpose' => $current->purpose,
            'email' => $current->email,
            'user_id' => $current->user_id,
            'flow_digest' => $root->flow_digest,
            'code_digest' => EmailChallenge::hashCode($id, $current->purpose, $code),
            'state_fingerprint' => $current->state_fingerprint,
            'operation_id' => $current->operation_id,
            'expires_at' => now()->addMinutes($current->purpose->lifetimeMinutes()),
            'failed_attempts' => 0,
            'created_at' => now(),
        ]);

        return [$next, $code];
    }

    private function generateCode(): string
    {
        $length = min(18, max(1, (int) config('auth.email_codes.code_length', 6)));
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }
}
