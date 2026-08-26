<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomFieldValue>
 */
final class CustomFieldValueFactory extends Factory
{
    protected $model = CustomFieldValue::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Team::factory(),
            'entity_type' => 'company',
            'entity_id' => static fn (array $attributes): string => (string) Company::factory()->create([
                'team_id' => $attributes['tenant_id'],
            ])->getKey(),
            'custom_field_id' => static fn (array $attributes): string => (string) CustomField::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'entity_type' => 'company',
                'type' => 'text',
            ])->getKey(),
            'string_value' => $this->faker->word(),
        ];
    }
}
