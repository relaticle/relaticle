<?php

declare(strict_types=1);

namespace Database\Factories\Relaticle\Chat\Models;

use App\Models\Workspace;
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
        $workspace = Workspace::factory()->create();
        $allowance = $workspace->plan->credits();
        $used = fake()->numberBetween(0, $allowance);

        $workspace->aiCreditBalance()->delete();

        return [
            'workspace_id' => $workspace->getKey(),
            'credits_remaining' => $allowance - $used,
            'credits_used' => $used,
            'purchased_credits' => 0,
            'period_starts_at' => now()->startOfMonth(),
            'period_ends_at' => now()->endOfMonth(),
        ];
    }
}
