<?php

declare(strict_types=1);

namespace Database\Factories\Relaticle\Chat\Models;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Relaticle\Chat\Models\ChatMessageFeedback;

/**
 * @extends Factory<ChatMessageFeedback>
 */
final class ChatMessageFeedbackFactory extends Factory
{
    protected $model = ChatMessageFeedback::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'conversation_id' => (string) Str::uuid7(),
            'message_id' => (string) Str::uuid7(),
            'rating' => fake()->randomElement([ChatMessageFeedback::RATING_UP, ChatMessageFeedback::RATING_DOWN]),
            'category' => null,
            'comment' => null,
            'model' => 'claude-sonnet-4',
        ];
    }
}
