<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Enums\Plan;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;

final readonly class SeedTeamCreditBalance
{
    public function handle(Team $team): AiCreditBalance
    {
        return DB::transaction(function () use ($team): AiCreditBalance {
            $existing = AiCreditBalance::query()
                ->where('team_id', $team->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing instanceof AiCreditBalance) {
                return $existing;
            }

            $plan = $team->plan ?? Plan::default();
            $allowance = $plan->credits();

            $balance = AiCreditBalance::query()->create([
                'team_id' => $team->getKey(),
                'credits_remaining' => $allowance,
                'credits_used' => 0,
                'period_starts_at' => now()->startOfMonth(),
                'period_ends_at' => now()->endOfMonth(),
            ]);

            AiCreditTransaction::query()->create([
                'team_id' => $team->getKey(),
                'user_id' => null,
                'conversation_id' => null,
                'idempotency_key' => 'seed-initial-'.Str::ulid(),
                'type' => AiCreditType::Adjustment,
                'model' => 'system',
                'input_tokens' => 0,
                'output_tokens' => 0,
                'credits_charged' => 0,
                'metadata' => [
                    'action' => 'seed_initial_balance',
                    'plan' => $plan->value,
                    'allowance_granted' => $allowance,
                ],
                'created_at' => now(),
            ]);

            return $balance;
        });
    }
}
