<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Models\EmailTemplate;

/**
 * @extends Factory<EmailTemplate>
 */
final class EmailTemplateFactory extends Factory
{
    protected $model = EmailTemplate::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'created_by' => User::factory(),
            'name' => $this->faker->words(3, true),
            'subject' => $this->faker->sentence(),
            'body_html' => '<p>'.$this->faker->paragraph().'</p>',
            'variables' => [],
            'is_shared' => false,
        ];
    }

    public function shared(): static
    {
        return $this->state(fn (): array => [
            'is_shared' => true,
        ]);
    }

    public function withVariables(): static
    {
        return $this->state(fn (): array => [
            'subject' => 'Hello {name}',
            'body_html' => '<p>Hi {first_name}, from {company}</p>',
            'variables' => ['name', 'first_name', 'company'],
        ]);
    }
}
