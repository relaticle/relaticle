<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;
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
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
        ];
    }
}
