<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailSignature;

/**
 * @extends Factory<EmailSignature>
 */
final class EmailSignatureFactory extends Factory
{
    protected $model = EmailSignature::class;

    public function definition(): array
    {
        return [
            'connected_account_id' => ConnectedAccount::factory(),
            'user_id' => User::factory(),
            'name' => $this->faker->words(2, true),
            'content_html' => '<p>'.$this->faker->sentence().'</p>',
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (): array => [
            'is_default' => true,
        ]);
    }
}
