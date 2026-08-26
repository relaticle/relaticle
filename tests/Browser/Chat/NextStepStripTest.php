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
 * The next-step strip: up to three prompts drafted by NextStepSuggester,
 * rendered at the tail of the transcript so they scroll with the turn they
 * belong to and tuck under the composer when the user reads back.
 *
 * @param  list<array{label: string, prompt: string}>  $nextSteps
 */
function seedNextStepTurn(string $conversationId, User $user, string $reply, array $nextSteps): void
{
    $rows = [
        ['role' => 'user', 'content' => 'What can you help me with?', 'meta' => '{}'],
        ['role' => 'assistant', 'content' => $reply, 'meta' => json_encode(['next_steps' => $nextSteps], JSON_THROW_ON_ERROR)],
    ];

    foreach ($rows as $index => $row) {
        DB::table('agent_conversation_messages')->insert([
            'id' => sprintf('next-step-%04d', $index),
            'conversation_id' => $conversationId,
            'participant_type' => 'user',
            'participant_id' => (string) $user->getKey(),
            'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
            'role' => $row['role'],
            'content' => $row['content'],
            'document' => ChatDocument::emptyJson(),
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '{}',
            'meta' => $row['meta'],
            'created_at' => now()->addSeconds($index),
            'updated_at' => now()->addSeconds($index),
        ]);
    }
}

it('renders the persisted next steps inside the transcript, in order', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $team->getKey(), 'next steps', $conversationId);

    seedNextStepTurn($conversationId, $user, 'Your workspace is empty.', [
        ['label' => 'Import your companies', 'prompt' => 'Help me import my companies from a file'],
        ['label' => 'Add your first contact', 'prompt' => 'Create a person called Dana Reed'],
        ['label' => 'Invite a teammate', 'prompt' => 'Invite a teammate to this workspace'],
    ]);

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('Your workspace is empty.');

    $page->assertCount('[data-next-step]', 3);

    $placement = $page->script(<<<'JS'
        (() => {
            const steps = Array.from(document.querySelectorAll('[data-next-step]'));
            const scroller = document.querySelector('[data-chat-context="conversation"] [role="log"]');
            const lastBubble = Array.from(scroller.querySelectorAll('[data-assistant-bubble]')).pop();

            return {
                labels: steps.map((el) => el.textContent.trim()),
                insideTranscript: steps.every((el) => scroller.contains(el)),
                // Follows the answer it belongs to, so scrolling carries it away.
                belowLastAnswer: !!lastBubble
                    && (steps[0].getBoundingClientRect().top >= lastBubble.getBoundingClientRect().bottom),
            };
        })();
    JS);

    expect($placement['labels'])->toBe([
        'Import your companies',
        'Add your first contact',
        'Invite a teammate',
    ])
        ->and($placement['insideTranscript'])->toBeTrue()
        ->and($placement['belowLastAnswer'])->toBeTrue();
});

it('sits at the floor of the transcript when the conversation is short', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $team->getKey(), 'next steps', $conversationId);

    seedNextStepTurn($conversationId, $user, 'Your workspace is empty.', [
        ['label' => 'Import your companies', 'prompt' => 'Help me import my companies from a file'],
    ]);

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('Your workspace is empty.');

    // One turn cannot fill the viewport, and the offer belongs where the eye
    // already is: against the composer, with the dead space above it.
    $layout = $page->script(<<<'JS'
        (() => {
            const log = document.querySelector('[data-chat-context="conversation"] [role="log"]');
            const step = document.querySelector('[data-next-step]');
            const bubble = Array.from(log.querySelectorAll('[data-assistant-bubble]')).pop();
            const logBox = log.getBoundingClientRect();

            return {
                scrolls: log.scrollHeight - log.clientHeight > 8,
                spaceBelowStrip: Math.round(logBox.bottom - step.getBoundingClientRect().bottom),
                spaceAboveStrip: Math.round(step.getBoundingClientRect().top - bubble.getBoundingClientRect().bottom),
            };
        })();
    JS);

    // Nothing to scroll, so the strip is genuinely at the bottom of the box and
    // only the container's own padding sits under it.
    expect($layout['scrolls'])->toBeFalse()
        ->and($layout['spaceBelowStrip'])->toBeLessThanOrEqual(32)
        ->and($layout['spaceAboveStrip'])->toBeGreaterThan($layout['spaceBelowStrip']);
});

it('sends the step prompt, not its label, and clears the strip', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $team->getKey(), 'next steps', $conversationId);

    seedNextStepTurn($conversationId, $user, 'Your workspace is empty.', [
        ['label' => 'Import your companies', 'prompt' => 'Help me import my companies from a file'],
    ]);

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('Your workspace is empty.');

    $resolve = ChatBrowser::resolveInterface();

    // The real sendMessage() runs, so the strip clears through the same path a
    // typed message takes. Only the network leg is stubbed, which is where the
    // provider round trip would otherwise be.
    $outcome = $page->script(<<<JS
        (() => {
            {$resolve}
            data.deliverMessage = (msg) => { window.__sentText = msg.content; };
            document.querySelector('[data-next-step]').click();
            return true;
        })();
    JS);

    expect($outcome)->toBeTrue();

    $page->assertMissing('[data-next-step]');

    $sent = $page->script(<<<JS
        (() => {
            {$resolve}
            return { sent: window.__sentText ?? null, remaining: data.nextSteps.length };
        })();
    JS);

    expect($sent['sent'])->toBe('Help me import my companies from a file')
        ->and($sent['remaining'])->toBe(0);
});

it('hides the strip while a turn is streaming', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $team->getKey(), 'next steps', $conversationId);

    seedNextStepTurn($conversationId, $user, 'Your workspace is empty.', [
        ['label' => 'Import your companies', 'prompt' => 'Help me import my companies from a file'],
    ]);

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('Your workspace is empty.');

    $resolve = ChatBrowser::resolveInterface();

    // A late broadcast belongs to the turn that already ended. Applying it
    // mid-stream would swap the strip under a cursor about to click it.
    $remaining = $page->script(<<<JS
        (() => {
            {$resolve}
            data.isStreaming = true;
            data.handleNextSteps({ steps: [{ label: 'Stale', prompt: 'Do the stale thing' }] });
            return data.nextSteps.map((s) => s.label);
        })();
    JS);

    expect($remaining)->toBe(['Import your companies']);
});
