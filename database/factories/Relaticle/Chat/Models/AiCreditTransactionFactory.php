<?php

declare(strict_types=1);

namespace Database\Factories\Relaticle\Chat\Models;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;

/**
 * @extends Factory<AiCreditTransaction>
 */
final class AiCreditTransactionFactory extends Factory
{
    protected $model = AiCreditTransaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $inputTokens = fake()->numberBetween(100, 5_000);
        $outputTokens = fake()->numberBetween(100, 2_000);

        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'conversation_id' => null,
            'idempotency_key' => 'test-'.fake()->unique()->uuid(),
            'type' => AiCreditType::Chat,
            'model' => fake()->randomElement([
                'claude-sonnet-4-6',
                'claude-opus-4-7',
                'gpt-5.5',
                'gemini-3-flash',
            ]),
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'credits_charged' => fake()->numberBetween(1, 5),
            'metadata' => ['tool_calls_count' => 0],
            'created_at' => now(),
        ];
    }

    public function adjustment(): self
    {
        return $this->state(fn (array $attributes): array => [
            'type' => AiCreditType::Adjustment,
            'user_id' => null,
            'model' => 'sysadmin',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'metadata' => [
                'delta' => 100,
                'reason' => 'test adjustment',
                'sysadmin_id' => '01h000000000000000000000ab',
            ],
        ]);
    }
}
