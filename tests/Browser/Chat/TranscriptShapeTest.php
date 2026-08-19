<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Tests\Helpers\ChatDocument;

mutates(ChatInterface::class);

/**
 * Message grouping + day separators: consecutive same-role messages under a
 * 3-minute gap (and no pending actions on the earlier one) render tightly
 * grouped (`data-grouped` on the later bubble); a calendar-day change between
 * two adjacent messages renders exactly one `data-day-separator` marker.
 */
function transcriptShapeInsertConversation(string $id, User $user, int|string $team): void
{
    DB::table('agent_conversations')->insert([
        'id' => $id,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $team,
        'title' => 'transcript shape',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function transcriptShapeInsertMessage(string $conversationId, User $user, string $content, Carbon $at): void
{
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'role' => 'user',
        'content' => $content,
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

/**
 * The initial HTML source carries the message payload inline (`@js($messages)`
 * inside the `x-data="chatInterface(...)"` attribute), so `assertSourceHas`
 * proves the server sent the data, not that Alpine's `x-for` has painted it
 * yet. Poll the live DOM for the expected bubble count before reading shape,
 * rather than trusting a single read right after navigation.
 */
function transcriptShapeWaitForBubbles(AwaitableWebpage $page, int $expected): int
{
    $count = 0;
    for ($i = 0; $i < 50; $i++) {
        $count = $page->script(<<<'JS'
            (() => document.querySelectorAll('[data-user-bubble]').length)();
        JS);
        if ($count === $expected) {
            return $count;
        }
        usleep(100_000);
    }

    return $count;
}

it('groups messages under a 3-minute gap and renders exactly one day separator across a day change', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    transcriptShapeInsertConversation($conversationId, $user, $team->getKey());

    $baseline = Carbon::parse('2026-08-19 10:00:00', 'UTC');

    // Chronological order: a message from yesterday, then three today, the
    // first pair one minute apart (must group), the third four minutes after
    // the second (must not group).
    transcriptShapeInsertMessage($conversationId, $user, 'Yesterday message', $baseline->copy()->subDay());
    transcriptShapeInsertMessage($conversationId, $user, 'First today message', $baseline->copy());
    transcriptShapeInsertMessage($conversationId, $user, 'Second today message', $baseline->copy()->addMinute());
    transcriptShapeInsertMessage($conversationId, $user, 'Third today message', $baseline->copy()->addMinutes(5));

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats/{$conversationId}")
        ->assertSourceHas('Third today message');

    expect(transcriptShapeWaitForBubbles($page, 4))->toBe(4);

    $shape = $page->script(<<<'JS'
        (() => {
            const bubbles = Array.from(document.querySelectorAll('[data-user-bubble]'));
            return {
                daySeparatorCount: document.querySelectorAll('[data-day-separator]').length,
                groupedCount: document.querySelectorAll('[data-user-bubble][data-grouped]').length,
                bubbles: bubbles.map((el) => ({
                    text: el.textContent.trim(),
                    grouped: el.hasAttribute('data-grouped'),
                })),
            };
        })();
    JS);

    expect($shape['daySeparatorCount'])->toBe(1);
    expect($shape['groupedCount'])->toBe(1);

    $byText = collect($shape['bubbles'])->keyBy(fn (array $b): string => $b['text']);

    expect($byText->get('Yesterday message')['grouped'] ?? null)->toBeFalse();
    expect($byText->get('First today message')['grouped'] ?? null)->toBeFalse();
    expect($byText->get('Second today message')['grouped'] ?? null)->toBeTrue();
    expect($byText->get('Third today message')['grouped'] ?? null)->toBeFalse();
});
