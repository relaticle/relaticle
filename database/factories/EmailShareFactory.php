<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailShare;

/**
 * @extends Factory<EmailShare>
 */
final class EmailShareFactory extends Factory
{
    protected $model = EmailShare::class;

    public function definition(): array
    {
        return [
            'email_id' => Email::factory(),
            'shared_by' => User::factory(),
            'shared_with' => User::factory(),
            'tier' => EmailPrivacyTier::FULL->value,
        ];
    }

    public function tier(EmailPrivacyTier $tier): static
    {
        return $this->state(fn (): array => [
            'tier' => $tier->value,
        ]);
    }
}
