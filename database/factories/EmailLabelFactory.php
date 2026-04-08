<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;
use Relaticle\EmailIntegration\Models\EmailLabel;

/**
 * @extends Factory<EmailLabel>
 */
final class EmailLabelFactory extends Factory
{
    protected $model = EmailLabel::class;

    public function definition(): array
    {
        return [
            'created_at' => Date::now(),
        ];
    }
}
