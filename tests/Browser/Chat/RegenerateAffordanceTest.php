<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Tests\Helpers\ChatBrowser;
use Tests\Helpers\ChatDocument;

mutates(ChatInterface::class);

/**
 * Regenerating replays the user message that produced a reply, so a reply with no
 * user message before it has nothing to replay. Every disabled reason the UI can
 * offer names a pending approval, so the button is hidden rather than shown with
 * a reason that is not true.
 */
function regenerateAffordanceInsertMessage(string $conversationId, User $user, string $role, string $content): void
{
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'role' => $role,
        'content' => $content,
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('hides the regenerate button on a reply that no user message precedes', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = ChatBrowser::seedConversation($user, $team->getKey(), 'Getting started');

    regenerateAffordanceInsertMessage($conversationId, $user, 'assistant', 'Here is what I found.');

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('Here is what I found.');

    $state = $page->script(<<<'JS'
        (() => {
            const buttons = Array.from(document.querySelectorAll('[data-regenerate-button]'));
            const shown = buttons.filter((b) => b.offsetParent !== null);
            return {
                shown: shown.length,
                labels: shown.map((b) => b.getAttribute('title')),
            };
        })()
    JS);

    expect($state['shown'])->toBe(0)
        ->and($state['labels'])->toBe([]);
});

it('still offers regenerate on a reply that a user message produced', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = ChatBrowser::seedConversation($user, $team->getKey(), 'Normal chat');

    regenerateAffordanceInsertMessage($conversationId, $user, 'user', 'How many companies do I have?');
    regenerateAffordanceInsertMessage($conversationId, $user, 'assistant', 'You have four companies.');

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('You have four companies.');

    $state = $page->script(<<<'JS'
        (() => {
            const shown = Array.from(document.querySelectorAll('[data-regenerate-button]'))
                .filter((b) => b.offsetParent !== null);
            return {
                shown: shown.length,
                disabled: shown.map((b) => b.disabled),
                labels: shown.map((b) => b.getAttribute('title')),
            };
        })()
    JS);

    expect($state['shown'])->toBe(1)
        ->and($state['disabled'])->toBe([false])
        ->and($state['labels'])->toBe(['Regenerate response']);
});
