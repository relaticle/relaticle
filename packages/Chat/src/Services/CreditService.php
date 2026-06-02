<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Actions\Chat\SeedTeamCreditBalance;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Enums\AiModel;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;

final readonly class CreditService
{
    public function hasCredits(Team $team): bool
    {
        $balance = $this->ensureBalance($team);

        return $balance->credits_remaining > 0;
    }

    /**
     * Atomically reserve one credit up-front. Prevents concurrent requests from
     * bypassing a non-atomic credit gate when the team has a small balance.
     */
    public function reserveCredit(Team $team): bool
    {
        $this->ensureBalance($team);

        return DB::transaction(function () use ($team): bool {
            $balance = AiCreditBalance::query()
                ->where('team_id', $team->getKey())
                ->lockForUpdate()
                ->first();

            if (! $balance instanceof AiCreditBalance || $balance->credits_remaining < 1) {
                return false;
            }

            $balance->update([
                'credits_remaining' => $balance->credits_remaining - 1,
                'credits_used' => $balance->credits_used + 1,
            ]);

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

    private function ensureBalance(Team $team): AiCreditBalance
    {
        $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->first();

        if ($balance instanceof AiCreditBalance) {
            return $balance;
        }

        return resolve(SeedTeamCreditBalance::class)->handle($team);
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
                $balance = AiCreditBalance::query()->create([
                    'team_id' => $team->getKey(),
                    'credits_remaining' => 0,
                    'credits_used' => 0,
                    'period_starts_at' => now()->startOfMonth(),
                    'period_ends_at' => now()->endOfMonth(),
                ]);
            }

            $balance->update([
                'credits_remaining' => max($balance->credits_remaining - $creditsCharged, 0),
                'credits_used' => $balance->credits_used + $creditsCharged,
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
        $multiplier = AiModel::multiplierForModelId($model);
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
                    $balance = AiCreditBalance::query()->create([
                        'team_id' => $team->getKey(),
                        'credits_remaining' => 0,
                        'credits_used' => 0,
                        'period_starts_at' => now()->startOfMonth(),
                        'period_ends_at' => now()->endOfMonth(),
                    ]);
                }

                $balance->update([
                    'credits_remaining' => max($balance->credits_remaining + $remainingDelta, 0),
                    'credits_used' => max($balance->credits_used + $usedDelta, 0),
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

            AiCreditBalance::query()->updateOrCreate(
                ['team_id' => $team->getKey()],
                [
                    'credits_remaining' => $allowance,
                    'credits_used' => 0,
                    'period_starts_at' => now()->startOfMonth(),
                    'period_ends_at' => now()->endOfMonth(),
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
                $balance = AiCreditBalance::query()->create([
                    'team_id' => $team->getKey(),
                    'credits_remaining' => 0,
                    'credits_used' => 0,
                    'period_starts_at' => now()->startOfMonth(),
                    'period_ends_at' => now()->endOfMonth(),
                ]);
            }

            if ($delta > 0) {
                $balance->update([
                    'credits_remaining' => $balance->credits_remaining + $delta,
                ]);
            } else {
                $revoke = abs($delta);
                $balance->update([
                    'credits_remaining' => max($balance->credits_remaining - $revoke, 0),
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
