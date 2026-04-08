<?php

declare(strict_types=1);

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;
use Relaticle\EmailIntegration\Models\PublicEmailDomain;

/**
 * @extends Factory<PublicEmailDomain>
 */
final class PublicEmailDomainFactory extends Factory
{
    protected $model = PublicEmailDomain::class;

    public function definition(): array
    {
        return [
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
        ];
    }
}
