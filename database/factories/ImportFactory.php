<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Models\Import;

/**
 * @extends Factory<Import>
 */
final class ImportFactory extends Factory
{
    protected $model = Import::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'team_id' => static fn (array $attributes): string => (string) Team::factory()->create([
                'user_id' => $attributes['user_id'],
            ])->getKey(),
            'entity_type' => ImportEntityType::People,
            'file_name' => $this->faker->unique()->lexify('import-????????.csv'),
            'status' => ImportStatus::Uploading,
            'total_rows' => 0,
            'headers' => [],
            'column_mappings' => [],
            'created_rows' => 0,
            'updated_rows' => 0,
            'skipped_rows' => 0,
            'failed_rows' => 0,
            'completed_at' => null,
        ];
    }
}
