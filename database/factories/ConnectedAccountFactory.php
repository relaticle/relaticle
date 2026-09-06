<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

/**
 * @extends Factory<ConnectedAccount>
 */
final class ConnectedAccountFactory extends Factory
{
    protected $model = ConnectedAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'provider' => EmailProvider::GMAIL,
            'provider_account_id' => $this->faker->uuid(),
            'email_address' => $this->faker->unique()->safeEmail(),
            'display_name' => $this->faker->name(),
            'access_token' => 'fake-token',
            'is_default' => false,
            'status' => EmailAccountStatus::ACTIVE,
        ];
    }

    public function azure(): static
    {
        return $this->state(fn (): array => [
            'provider' => EmailProvider::AZURE,
        ]);
    }

    public function default(): static
    {
        return $this->state(fn (): array => [
            'is_default' => true,
        ]);
    }

    public function disconnected(): static
    {
        return $this->state(fn (): array => [
            'status' => EmailAccountStatus::DISCONNECTED,
        ]);
    }

    public function error(): static
    {
        return $this->state(fn (): array => [
            'status' => EmailAccountStatus::ERROR,
            'last_error' => 'Token refresh failed',
        ]);
    }

    public function withSendLimits(int $hourly = 20, int $daily = 200): static
    {
        return $this->state(fn (): array => [
            'hourly_send_limit' => $hourly,
            'daily_send_limit' => $daily,
        ]);
    }

    public function withoutSend(): static
    {
        return $this->state(fn (): array => [
            'capabilities' => [
                'email' => true,
                'send' => false,
                'calendar' => false,
            ],
        ]);
    }
}
