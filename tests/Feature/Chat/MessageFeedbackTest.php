<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Actions\ListConversationMessages;
use Relaticle\Chat\Models\ChatMessageFeedback;

function seedFeedbackConversation(User $user): array
{
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => 'feedback test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messageIds = [];

    foreach ([['user', 'hello'], ['assistant', 'hi there']] as [$role, $content]) {
        $id = (string) Str::uuid7();
        $messageIds[$role] = $id;

        DB::table('agent_conversation_messages')->insert([
            'id' => $id,
            'conversation_id' => $conversationId,
            'user_id' => (string) $user->getKey(),
            'agent' => 'test',
            'role' => $role,
            'content' => $content,
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => json_encode(['model' => 'claude-sonnet-4']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return [$conversationId, $messageIds];
}

it('records a thumbs up', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    [, $ids] = seedFeedbackConversation($user);

    $this->actingAs($user)
        ->postJson("/chat/messages/{$ids['assistant']}/feedback", ['rating' => 'up'])
        ->assertOk()
        ->assertJson(['rating' => 'up']);

    $row = ChatMessageFeedback::query()->where('message_id', $ids['assistant'])->first();

    expect($row)->not->toBeNull()
        ->and($row->rating)->toBe('up')
        ->and($row->model)->toBe('claude-sonnet-4')
        ->and($row->team_id)->toBe($user->currentTeam->getKey());
});

it('switches the rating in place instead of stacking rows', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    [, $ids] = seedFeedbackConversation($user);

    $this->actingAs($user)->postJson("/chat/messages/{$ids['assistant']}/feedback", ['rating' => 'up'])->assertOk();
    $this->actingAs($user)->postJson("/chat/messages/{$ids['assistant']}/feedback", [
        'rating' => 'down',
        'category' => 'inaccurate',
        'comment' => 'numbers were wrong',
    ])->assertOk();

    $rows = ChatMessageFeedback::query()->where('message_id', $ids['assistant'])->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->rating)->toBe('down')
        ->and($rows->first()->category)->toBe('inaccurate')
        ->and($rows->first()->comment)->toBe('numbers were wrong');
});

it('retracts a rating via DELETE', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    [, $ids] = seedFeedbackConversation($user);

    $this->actingAs($user)->postJson("/chat/messages/{$ids['assistant']}/feedback", ['rating' => 'down'])->assertOk();
    $this->actingAs($user)->deleteJson("/chat/messages/{$ids['assistant']}/feedback")->assertOk();

    expect(ChatMessageFeedback::query()->where('message_id', $ids['assistant'])->exists())->toBeFalse();
});

it('rejects rating a user message', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    [, $ids] = seedFeedbackConversation($user);

    $this->actingAs($user)
        ->postJson("/chat/messages/{$ids['user']}/feedback", ['rating' => 'up'])
        ->assertNotFound();
});

it('hides foreign messages with 404', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    [, $ids] = seedFeedbackConversation($owner);

    $intruder = User::factory()->withPersonalTeam()->create();

    $this->actingAs($intruder)
        ->postJson("/chat/messages/{$ids['assistant']}/feedback", ['rating' => 'up'])
        ->assertNotFound();
});

it('rejects unknown categories', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    [, $ids] = seedFeedbackConversation($user);

    $this->actingAs($user)
        ->postJson("/chat/messages/{$ids['assistant']}/feedback", ['rating' => 'down', 'category' => 'sentient'])
        ->assertStatus(422);
});

it('includes the current user feedback in the rendered transcript', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    [$conversationId, $ids] = seedFeedbackConversation($user);

    $this->actingAs($user)->postJson("/chat/messages/{$ids['assistant']}/feedback", [
        'rating' => 'down',
        'category' => 'too_slow',
    ])->assertOk();

    $messages = resolve(ListConversationMessages::class)->execute($user, $conversationId);

    $assistant = collect($messages)->firstWhere('role', 'assistant');

    expect($assistant['feedback'])->toBe(['rating' => 'down', 'category' => 'too_slow']);
});
