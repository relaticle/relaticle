<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Models\EmailConversation;

/**
 * @extends Factory<EmailConversation>
 */
final class EmailConversationFactory extends Factory
{
    protected $model = EmailConversation::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'conversation_key' => hash('sha256', fake()->uuid()),
            'message_count' => 1,
            'first_message_at' => now()->subHour(),
            'last_message_at' => now(),
            'has_attachments' => false,
            'is_automated' => false,
        ];
    }
}
