<?php

declare(strict_types=1);

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Relaticle\EmailIntegration\Models\PublicEmailDomain;

final class PublicEmailDomainFactory extends Factory
{
    protected $model = PublicEmailDomain::class;

    public function definition(): array
    {
        return [
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];
    }
}
