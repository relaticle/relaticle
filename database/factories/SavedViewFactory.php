<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Enums\SavedViewType;
use App\Models\SavedView;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedView>
 */
final class SavedViewFactory extends Factory
{
    protected $model = SavedView::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entity' => CrmEntity::Company,
            'type' => SavedViewType::Table,
            'name' => fake()->unique()->words(2, true),
            'is_shared' => false,
            'state' => new TableStateData,
        ];
    }

    public function shared(): self
    {
        return $this->state(['is_shared' => true]);
    }
}
