<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomFieldSection;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\CustomFields\Data\CustomFieldSectionSettingsData;
use Relaticle\CustomFields\Enums\CustomFieldSectionType;

/**
 * @extends Factory<CustomFieldSection>
 */
final class CustomFieldSectionFactory extends Factory
{
    protected $model = CustomFieldSection::class;

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
            'code' => 'section_'.$this->faker->unique()->lexify('????????'),
            'name' => $this->faker->words(2, true),
            'type' => CustomFieldSectionType::SECTION,
            'sort_order' => 1,
            'active' => true,
            'system_defined' => false,
            'settings' => new CustomFieldSectionSettingsData,
        ];
    }
}
