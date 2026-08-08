<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Actions\Chat\SeedTeamCreditBalance;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;

final readonly class CreditService
{
    public function __construct(private ModelRegistry $registry, private CreditPeriodResolver $periods) {}

    public function hasCredits(Team $team): bool
    {
        $balance = $this->ensureBalance($team);

        return $balance->credits_remaining > 0;
    }

    /**
     * Atomically reserve one credit up-front. Prevents concurrent requests from
     * bypassing a non-atomic credit gate when the team has a small balance.
     *
     * With a $reservationKey ("reserve-{turnId}") the reservation is journaled
     * as a ledger row and becomes idempotent: a retried job (e.g. released by
     * WithoutOverlapping before handle() ran) re-calls this and gets `true`
     * without decrementing twice. The row also lets the orphan sweeper refund
     * reservations whose turn crashed between reserve and settle (R2).
     */
    public function reserveCredit(Team $team, ?string $reservationKey = null, ?string $conversationId = null, ?string $userId = null): bool
    {
        $this->ensureBalance($team);

        return DB::transaction(function () use ($team, $reservationKey, $conversationId, $userId): bool {
            if ($reservationKey !== null) {
                $alreadyReserved = AiCreditTransaction::query()
                    ->where('team_id', $team->getKey())
                    ->where('idempotency_key', $reservationKey)
                    ->exists();

                if ($alreadyReserved) {
                    return true;
                }
            }

            $balance = AiCreditBalance::query()
                ->where('team_id', $team->getKey())
                ->lockForUpdate()
                ->first();

            if (! $balance instanceof AiCreditBalance || $balance->credits_remaining < 1) {
                return false;
            }

            $newRemaining = $balance->credits_remaining - 1;

            $balance->update([
                'credits_remaining' => $newRemaining,
                'credits_used' => $balance->credits_used + 1,
                'purchased_credits' => min($balance->purchased_credits, $newRemaining),
            ]);

            if ($reservationKey !== null) {
                AiCreditTransaction::query()->insertOrIgnore([
                    'id' => (string) Str::ulid(),
                    'team_id' => $team->getKey(),
                    'user_id' => $userId,
                    'conversation_id' => $conversationId,
                    'idempotency_key' => $reservationKey,
                    'type' => AiCreditType::Reservation->value,
                    'model' => 'system',
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'credits_charged' => 1,
                    'metadata' => json_encode(['reason' => 'upfront_reservation'], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            }

            return true;
        });
    }

    /**
     * Refund a reserved credit. Only valid before the model produced billable
     * work. Idempotent on $resolutionKey and mutually exclusive with settlement
     * (both write the same unique key).
     */
    public function refundReservation(Team $team, int $credits = 1, string $resolutionKey = '', ?string $conversationId = null): void
    {
        $this->recordResolution(
            team: $team,
            resolutionKey: $resolutionKey,
            type: AiCreditType::Refund,
            model: 'system',
            inputTokens: 0,
            outputTokens: 0,
            creditsCharged: $credits,
            remainingDelta: $credits,
            usedDelta: -$credits,
            userId: null,
            conversationId: $conversationId,
            metadata: ['reason' => 'reservation_refund'],
        );
    }

    public function getBalance(Team $team): int
    {
        $balance = AiCreditBalance::query()
            ->where('team_id', $team->getKey())
            ->first();

        if (! $balance instanceof AiCreditBalance) {
            return 0;
        }

        return $balance->credits_remaining;
    }

    /**
     * Grant prepaid credits from a completed pack checkout. Idempotent on
     * $idempotencyKey (the Stripe checkout session id) via the ledger's
     * (team_id, idempotency_key) unique index — webhook replays are a no-op.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function addPurchasedCredits(Team $team, int $credits, string $idempotencyKey, array $metadata = []): bool
    {
        $this->ensureBalance($team);

        return DB::transaction(function () use ($team, $credits, $idempotencyKey, $metadata): bool {
            $inserted = AiCreditTransaction::query()->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'team_id' => $team->getKey(),
                'user_id' => null,
                'conversation_id' => null,
                'idempotency_key' => $idempotencyKey,
                'type' => AiCreditType::Purchase->value,
                'model' => 'stripe',
                'input_tokens' => 0,
                'output_tokens' => 0,
                'credits_charged' => $credits,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            if ($inserted === 0) {
                return false;
            }

            $balance = AiCreditBalance::query()
                ->where('team_id', $team->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $balance->update([
                'credits_remaining' => $balance->credits_remaining + $credits,
                'purchased_credits' => $balance->purchased_credits + $credits,
            ]);

            return true;
        });
    }

    private function ensureBalance(Team $team): AiCreditBalance
    {
        $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->first();

        if ($balance instanceof AiCreditBalance) {
            return $balance;
        }

        return resolve(SeedTeamCreditBalance::class)->execute($team);
    }

    public function deduct(
        Team $team,
        User $user,
        AiCreditType $type,
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $toolCallsCount = 0,
        ?string $conversationId = null,
        ?string $idempotencyKey = null,
    ): void {
        if ($idempotencyKey !== null && AiCreditTransaction::query()
            ->where('team_id', $team->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->exists()
        ) {
            return;
        }

        $creditsCharged = $this->calculateCredits($model, $toolCallsCount);

        DB::transaction(function () use ($team, $user, $type, $model, $inputTokens, $outputTokens, $creditsCharged, $toolCallsCount, $conversationId, $idempotencyKey): void {
            $balance = AiCreditBalance::query()
                ->where('team_id', $team->getKey())
                ->lockForUpdate()
                ->first();

            if (! $balance instanceof AiCreditBalance) {
                $bounds = $this->periods->boundsFor($team);

                $balance = AiCreditBalance::query()->create([
                    'team_id' => $team->getKey(),
                    'credits_remaining' => 0,
                    'credits_used' => 0,
                    'purchased_credits' => 0,
                    'period_starts_at' => $bounds['start'],
                    'period_ends_at' => $bounds['end'],
                ]);
            }

            $newRemaining = max($balance->credits_remaining - $creditsCharged, 0);

            $balance->update([
                'credits_remaining' => $newRemaining,
                'credits_used' => $balance->credits_used + $creditsCharged,
                'purchased_credits' => min($balance->purchased_credits, $newRemaining),
            ]);

            AiCreditTransaction::query()->create([
                'team_id' => $team->getKey(),
                'user_id' => $user->getKey(),
                'conversation_id' => $conversationId,
                'idempotency_key' => $idempotencyKey ?? 'deduct-'.Str::ulid(),
                'type' => $type,
                'model' => $model,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'credits_charged' => $creditsCharged,
                'metadata' => ['tool_calls_count' => $toolCallsCount],
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Settle a reserved credit by charging the difference between the real cost
     * and the already-reserved credits. Idempotent on $resolutionKey.
     */
    public function settleReservation(
        Team $team,
        User $user,
        AiCreditType $type,
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $toolCallsCount = 0,
        ?string $conversationId = null,
        int $reservedCredits = 1,
        string $resolutionKey = '',
    ): void {
        $creditsCharged = $this->calculateCredits($model, $toolCallsCount);
        $adjustment = $creditsCharged - $reservedCredits;

        $this->recordResolution(
            team: $team,
            resolutionKey: $resolutionKey,
            type: $type,
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            creditsCharged: $creditsCharged,
            remainingDelta: -$adjustment,
            usedDelta: $adjustment,
            userId: (string) $user->getKey(),
            conversationId: $conversationId,
            metadata: ['tool_calls_count' => $toolCallsCount],
        );
    }

    /**
     * Finalize a reservation at the reserved minimum (no extra charge, no refund).
     * Used for early-ended turns (cancel, timeout, mid-stream error) so the work
     * the user already received is paid for. Idempotent on $resolutionKey.
     */
    public function settleReservedMinimum(
        Team $team,
        User $user,
        ?string $conversationId,
        string $resolutionKey,
        string $reason,
        int $reservedCredits = 1,
    ): void {
        $this->recordResolution(
            team: $team,
            resolutionKey: $resolutionKey,
            type: AiCreditType::Chat,
            model: 'incomplete',
            inputTokens: 0,
            outputTokens: 0,
            creditsCharged: $reservedCredits,
            remainingDelta: 0,
            usedDelta: 0,
            userId: (string) $user->getKey(),
            conversationId: $conversationId,
            metadata: ['reason' => $reason],
        );
    }

    public function calculateCredits(string $model, int $toolCallsCount): int
    {
        $multiplier = $this->registry->multiplierFor($model);
        $toolBonus = (float) config('chat.tool_call_credit_bonus', 0.5);

        $raw = ($multiplier) + ($toolCallsCount * $toolBonus);

        return max(1, (int) ceil($raw));
    }

    /**
     * Idempotently record a reservation resolution (settle or refund) and apply
     * its balance delta. The (team_id, idempotency_key) unique index makes a
     * duplicate or concurrent call a silent no-op. Returns true when this call
     * was the one that resolved the reservation.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function recordResolution(
        Team $team,
        string $resolutionKey,
        AiCreditType $type,
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $creditsCharged,
        int $remainingDelta,
        int $usedDelta,
        ?string $userId,
        ?string $conversationId,
        array $metadata,
    ): bool {
        return DB::transaction(function () use (
            $team, $resolutionKey, $type, $model, $inputTokens, $outputTokens,
            $creditsCharged, $remainingDelta, $usedDelta, $userId, $conversationId, $metadata,
        ): bool {
            $inserted = AiCreditTransaction::query()->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'team_id' => $team->getKey(),
                'user_id' => $userId,
                'conversation_id' => $conversationId,
                'idempotency_key' => $resolutionKey,
                'type' => $type->value,
                'model' => $model,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'credits_charged' => $creditsCharged,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            if ($inserted === 0) {
                return false;
            }

            if ($remainingDelta !== 0 || $usedDelta !== 0) {
                $balance = AiCreditBalance::query()
                    ->where('team_id', $team->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $balance instanceof AiCreditBalance) {
                    $bounds = $this->periods->boundsFor($team);

                    $balance = AiCreditBalance::query()->create([
                        'team_id' => $team->getKey(),
                        'credits_remaining' => 0,
                        'credits_used' => 0,
                        'purchased_credits' => 0,
                        'period_starts_at' => $bounds['start'],
                        'period_ends_at' => $bounds['end'],
                    ]);
                }

                $newRemaining = max($balance->credits_remaining + $remainingDelta, 0);

                $balance->update([
                    'credits_remaining' => $newRemaining,
                    'credits_used' => max($balance->credits_used + $usedDelta, 0),
                    'purchased_credits' => min($balance->purchased_credits, $newRemaining),
                ]);
            }

            return true;
        });
    }

    public function resetPeriod(Team $team, ?string $sysadminId = null): void
    {
        DB::transaction(function () use ($team, $sysadminId): void {
            $plan = $team->plan;
            $allowance = $plan->credits();

            $previous = AiCreditBalance::query()
                ->where('team_id', $team->getKey())
                ->lockForUpdate()
                ->first();

            $bounds = $this->periods->boundsFor($team);
            $purchased = $previous instanceof AiCreditBalance ? $previous->purchased_credits : 0;

            AiCreditBalance::query()->updateOrCreate(
                ['team_id' => $team->getKey()],
                [
                    'credits_remaining' => $allowance + $purchased,
                    'credits_used' => 0,
                    'purchased_credits' => $purchased,
                    'period_starts_at' => $bounds['start'],
                    'period_ends_at' => $bounds['end'],
                ],
            );

            AiCreditTransaction::query()->create([
                'team_id' => $team->getKey(),
                'user_id' => null,
                'conversation_id' => null,
                'idempotency_key' => 'sysadmin-reset-'.Str::ulid(),
                'type' => AiCreditType::Adjustment,
                'model' => 'sysadmin',
                'input_tokens' => 0,
                'output_tokens' => 0,
                'credits_charged' => 0,
                'metadata' => [
                    'action' => 'reset_period',
                    'plan' => $plan->value,
                    'allowance_granted' => $allowance,
                    'previous_credits_remaining' => $previous?->credits_remaining,
                    'previous_credits_used' => $previous?->credits_used,
                    'sysadmin_id' => $sysadminId,
                ],
                'created_at' => now(),
            ]);
        });
    }

    public function adjust(Team $team, int $delta, string $reason, string $sysadminId): void
    {
        if ($delta === 0) {
            return;
        }

        DB::transaction(function () use ($team, $delta, $reason, $sysadminId): void {
            $balance = AiCreditBalance::query()
                ->where('team_id', $team->getKey())
                ->lockForUpdate()
                ->first();

            if (! $balance instanceof AiCreditBalance) {
                $bounds = $this->periods->boundsFor($team);

                $balance = AiCreditBalance::query()->create([
                    'team_id' => $team->getKey(),
                    'credits_remaining' => 0,
                    'credits_used' => 0,
                    'purchased_credits' => 0,
                    'period_starts_at' => $bounds['start'],
                    'period_ends_at' => $bounds['end'],
                ]);
            }

            if ($delta > 0) {
                $balance->update([
                    'credits_remaining' => $balance->credits_remaining + $delta,
                ]);
            } else {
                $revoke = abs($delta);
                $newRemaining = max($balance->credits_remaining - $revoke, 0);

                $balance->update([
                    'credits_remaining' => $newRemaining,
                    'purchased_credits' => min($balance->purchased_credits, $newRemaining),
                ]);
            }

            AiCreditTransaction::query()->create([
                'team_id' => $team->getKey(),
                'user_id' => null,
                'conversation_id' => null,
                'idempotency_key' => 'sysadmin-adjust-'.Str::ulid(),
                'type' => AiCreditType::Adjustment,
                'model' => 'sysadmin',
                'input_tokens' => 0,
                'output_tokens' => 0,
                'credits_charged' => abs($delta),
                'metadata' => [
                    'delta' => $delta,
                    'reason' => $reason,
                    'sysadmin_id' => $sysadminId,
                ],
                'created_at' => now(),
            ]);
        });
    }
}
