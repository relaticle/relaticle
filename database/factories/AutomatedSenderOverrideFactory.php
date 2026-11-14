<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Models\AutomatedSenderOverride;

/**
 * @extends Factory<AutomatedSenderOverride>
 */
final class AutomatedSenderOverrideFactory extends Factory
{
    protected $model = AutomatedSenderOverride::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'email_address' => fake()->unique()->safeEmail(),
        ];
    }
}
