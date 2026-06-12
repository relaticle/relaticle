<?php

declare(strict_types=1);

namespace Database\Factories\Relaticle\Chat\Models;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\Chat\Models\AiCreditBalance;

/**
 * @extends Factory<AiCreditBalance>
 */
final class AiCreditBalanceFactory extends Factory
{
    protected $model = AiCreditBalance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $team = Team::factory()->create();
        $allowance = $team->plan->credits();
        $used = fake()->numberBetween(0, $allowance);

        $team->aiCreditBalance()->delete();

        return [
            'team_id' => $team->getKey(),
            'credits_remaining' => $allowance - $used,
            'credits_used' => $used,
            'period_starts_at' => now()->startOfMonth(),
            'period_ends_at' => now()->endOfMonth(),
        ];
    }
}
