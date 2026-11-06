<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Models\EmailConversationState;

/**
 * @extends Factory<EmailConversationState>
 */
final class EmailConversationStateFactory extends Factory
{
    protected $model = EmailConversationState::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'conversation_key' => hash('sha256', fake()->uuid()),
            'done_at' => now(),
        ];
    }
}
