<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomField;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;

/**
 * @extends Factory<CustomField>
 */
final class CustomFieldFactory extends Factory
{
    protected $model = CustomField::class;

    public function definition(): array
    {
        return [
            'code' => $this->faker->unique()->word(),
            'name' => $this->faker->name(),
            'type' => $this->faker->randomElement(['text', 'number', 'link', 'textarea', 'date', 'select']),
            'entity_type' => $this->faker->randomElement(['company', 'people', 'opportunity', 'task', 'note']),
            'sort_order' => 1,
            'validation_rules' => [],
            'active' => true,
            'system_defined' => false,
            'settings' => new CustomFieldSettingsData(encrypted: false),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function configure(): static
    {
        if (config('scribe.generating')) {
            return $this->state([
                'entity_type' => 'company',
                'code' => 'industry',
                'name' => 'Industry',
                'type' => 'select',
            ]);
        }

        return $this;
    }
}
