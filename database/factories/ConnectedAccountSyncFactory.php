<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\ConnectedAccountSync;

/**
 * @extends Factory<ConnectedAccountSync>
 */
final class ConnectedAccountSyncFactory extends Factory
{
    protected $model = ConnectedAccountSync::class;

    public function definition(): array
    {
        return [
            'connected_account_id' => ConnectedAccount::factory(),
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
            'emails_synced' => $this->faker->numberBetween(0, 50),
            'errors_encountered' => 0,
            'status' => 'completed',
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => 'failed',
            'completed_at' => null,
            'error_details' => 'Token expired',
        ]);
    }
}
