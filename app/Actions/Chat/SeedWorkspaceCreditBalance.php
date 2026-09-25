<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Enums\Plan;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\CreditPeriodResolver;

final readonly class SeedWorkspaceCreditBalance
{
    public function __construct(private CreditPeriodResolver $periods) {}

    public function execute(Workspace $workspace): AiCreditBalance
    {
        return DB::transaction(function () use ($workspace): AiCreditBalance {
            $existing = AiCreditBalance::query()
                ->where('workspace_id', $workspace->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing instanceof AiCreditBalance) {
                return $existing;
            }

            $plan = $workspace->plan ?? Plan::default();
            $allowance = $plan->credits();

            $bounds = $this->periods->boundsFor($workspace);

            $balance = AiCreditBalance::query()->create([
                'workspace_id' => $workspace->getKey(),
                'credits_remaining' => $allowance,
                'credits_used' => 0,
                'purchased_credits' => 0,
                'period_starts_at' => $bounds['start'],
                'period_ends_at' => $bounds['end'],
            ]);

            AiCreditTransaction::query()->create([
                'workspace_id' => $workspace->getKey(),
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
