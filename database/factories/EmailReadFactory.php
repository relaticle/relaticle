<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailRead;

/**
 * @extends Factory<EmailRead>
 */
final class EmailReadFactory extends Factory
{
    protected $model = EmailRead::class;

    public function definition(): array
    {
        return [
            'email_id' => Email::factory(),
            'user_id' => User::factory(),
            'read_at' => now(),
        ];
    }
}
