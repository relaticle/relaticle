<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FAQRCode\Google2FA;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

/**
 * @extends Factory<SystemAdministrator>
 */
final class SystemAdministratorFactory extends Factory
{
    protected $model = SystemAdministrator::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'app_authentication_secret' => resolve(Google2FA::class)->generateSecretKey(16),
            'role' => SystemAdministratorRole::SuperAdministrator,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Enrolled in app authentication is the default, because the sysadmin panel
     * requires a second factor and an unenrolled administrator reaches nothing
     * but the enrolment page.
     */
    public function unenrolled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'app_authentication_secret' => null,
            'app_authentication_recovery_codes' => null,
        ]);
    }

    /**
     * Indicate that the administrator may write everything but delete nothing.
     */
    public function administrator(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => SystemAdministratorRole::Administrator,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    /** @phpstan-return static */
    public function configure(): static
    {
        return $this->sequence(fn (Sequence $sequence): array => [
            'created_at' => now()->subMinutes($sequence->index),
            'updated_at' => now()->subMinutes($sequence->index),
        ]);
    }
}
