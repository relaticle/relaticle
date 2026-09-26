<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

/**
 * @extends Factory<EmailAccessRequest>
 */
final class EmailAccessRequestFactory extends Factory
{
    protected $model = EmailAccessRequest::class;

    public function definition(): array
    {
        return [
            'requester_id' => User::factory(),
            'owner_id' => User::factory(),
            'email_id' => Email::factory(),
            'tier_requested' => EmailPrivacyTier::FULL->value,
            'status' => EmailAccessRequestStatus::PENDING,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => EmailAccessRequestStatus::PENDING,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => EmailAccessRequestStatus::APPROVED,
        ]);
    }

    public function denied(): static
    {
        return $this->state(fn (): array => [
            'status' => EmailAccessRequestStatus::DENIED,
        ]);
    }

    public function forTier(EmailPrivacyTier $tier): static
    {
        return $this->state(fn (): array => [
            'tier_requested' => $tier->value,
        ]);
    }
}
