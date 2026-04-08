<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;
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
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
        ];
    }
}
