<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Models\ChatMessageFeedback;
use Relaticle\SystemAdmin\Filament\Widgets\ChatFeedbackStatsWidget;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(ChatFeedbackStatsWidget::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(['timezone' => 'Asia/Yerevan']), 'sysadmin');
    Filament::setCurrentPanel('sysadmin');
});

/**
 * chat_message_feedback carries real foreign keys to agent_conversations and
 * agent_conversation_messages, and neither has a factory, so the parent rows are
 * inserted directly. ChatMessageFeedbackFactory cannot be used here: it fills
 * both keys with dangling uuid7 values and violates those constraints.
 */
function seedFeedbackAt(string $utc): ChatMessageFeedback
{
    $user = User::factory()->withPersonalTeam()->create();
    $conversationId = (string) Str::uuid7();
    $messageId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => 'feedback window test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => $messageId,
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'agent' => 'test',
        'role' => 'assistant',
        'content' => 'answer',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ChatMessageFeedback::query()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => $conversationId,
        'message_id' => $messageId,
        'rating' => ChatMessageFeedback::RATING_UP,
        'model' => 'claude-sonnet-4',
        'created_at' => Date::parse($utc, 'UTC'),
    ]);
}

it('renders the widget', function (): void {
    livewire(ChatFeedbackStatsWidget::class)
        ->assertSuccessful();
});

it('opens its window at midnight on the administrator calendar', function (): void {
    $this->travelTo(Date::parse('2026-08-27 10:31:00', 'UTC'));

    // Jul 28 19:00 in Yerevan, the day before the 30 day window opens.
    seedFeedbackAt('2026-07-28 15:00:00');

    // Jul 29 01:00 in Yerevan, the first day inside it.
    seedFeedbackAt('2026-07-28 21:00:00');

    $stats = invade(livewire(ChatFeedbackStatsWidget::class)->assertOk()->instance())->getStats();

    expect($stats[0]->getValue())->toBe('1');
});
