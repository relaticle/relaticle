<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBody;

/**
 * @extends Factory<EmailBody>
 */
final class EmailBodyFactory extends Factory
{
    protected $model = EmailBody::class;

    public function definition(): array
    {
        return [
            'email_id' => Email::factory(),
            'body_text' => $this->faker->paragraph(),
            'body_html' => '<p>'.$this->faker->paragraph().'</p>',
        ];
    }
}
