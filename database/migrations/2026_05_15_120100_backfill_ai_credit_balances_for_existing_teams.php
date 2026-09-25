<?php

declare(strict_types=1);

use App\Enums\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\AiCreditType;

/**
 * Written against table names rather than models: a migration has to keep
 * working after the classes it once named are renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $periodStart = now()->startOfMonth();
        $periodEnd = now()->endOfMonth();

        DB::table('teams')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw(1)
                ->from('ai_credit_balances')
                ->whereColumn('ai_credit_balances.team_id', 'teams.id'))
            ->orderBy('id')
            ->each(function (object $team) use ($periodStart, $periodEnd): void {
                $allowance = (Plan::tryFrom((string) $team->plan) ?? Plan::default())->credits();

                DB::table('ai_credit_balances')->insert([
                    'id' => (string) Str::ulid(),
                    'team_id' => $team->id,
                    'credits_remaining' => $allowance,
                    'credits_used' => 0,
                    'purchased_credits' => 0,
                    'period_starts_at' => $periodStart,
                    'period_ends_at' => $periodEnd,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('ai_credit_transactions')->insert([
                    'id' => (string) Str::ulid(),
                    'team_id' => $team->id,
                    'idempotency_key' => 'seed-initial-'.Str::ulid(),
                    'type' => AiCreditType::Adjustment->value,
                    'model' => 'system',
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'credits_charged' => 0,
                    'metadata' => json_encode([
                        'action' => 'seed_initial_balance',
                        'allowance_granted' => $allowance,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }
};
