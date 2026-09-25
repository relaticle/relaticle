<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EmailChallengePurpose;
use App\Models\EmailChallenge;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmailChallenge>
 */
final class EmailChallengeFactory extends Factory
{
    protected $model = EmailChallenge::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = (string) Str::ulid();
        $purpose = EmailChallengePurpose::VERIFY_EMAIL;
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $secret = Str::random(40);

        return [
            'id' => $id,
            'flow_id' => $id,
            'generation' => 1,
            'purpose' => $purpose,
            'email' => fake()->unique()->safeEmail(),
            'user_id' => null,
            'flow_digest' => EmailChallenge::hashFlowSecret($secret),
            'code_digest' => EmailChallenge::hashCode($id, $purpose, $code),
            'state_fingerprint' => null,
            'operation_id' => null,
            'expires_at' => now()->addMinutes($purpose->lifetimeMinutes()),
            'failed_attempts' => 0,
            'consumed_at' => null,
            'invalidated_at' => null,
            'invalidation_reason' => null,
            'created_at' => now(),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);
    }

    public function withCode(string &$rawCode = ''): static
    {
        return $this->state(function (array $attributes) use (&$rawCode): array {
            $flowId = is_string($attributes['id'] ?? null) ? $attributes['id'] : (string) Str::ulid();
            $purpose = $attributes['purpose'] instanceof EmailChallengePurpose ? $attributes['purpose'] : EmailChallengePurpose::VERIFY_EMAIL;
            $rawCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            return [
                'code_digest' => EmailChallenge::hashCode($flowId, $purpose, $rawCode),
            ];
        });
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function consumed(): static
    {
        return $this->state(fn (): array => [
            'consumed_at' => now(),
        ]);
    }

    public function invalidated(string $reason = 'superseded'): static
    {
        return $this->state(fn (): array => [
            'invalidated_at' => now(),
            'invalidation_reason' => $reason,
        ]);
    }
}
