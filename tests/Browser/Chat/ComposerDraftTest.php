<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Playwright\Playwright;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Tests\Helpers\ChatBrowser;

mutates(ChatInterface::class);

/**
 * Drafts persist to localStorage under `chat.draft.{conversationId}` (the
 * literal segment `new` for a composer that has not created a conversation
 * yet), debounced 400ms after the TipTap document changes.
 *
 * Every wait below polls inside a single script() call rather than looping
 * PHP-side calls, and every call that awaits a real network round trip
 * (sendMessage()) is wrapped in a widened Playwright::usingTimeout(): see
 * SendStateTest's docblock for why. The same reasoning extends to typing —
 * AwaitableWebpage retries any single method call that does not settle
 * within the (1s default) per-attempt budget, and a retried multi-keystroke
 * `keys()` call would replay every keystroke, not just resume it. `type()`
 * (a single fill() action) avoids that entirely, so it is used here instead
 * of MentionPickerTest's char-by-char keys() (that file needs per-keystroke
 * timing to drive the suggestion popup; this one does not).
 */
function chatInsertConversation(string $id, User $user, int|string $team, string $title = 'draft test'): void
{
    DB::table('agent_conversations')->insert([
        'id' => $id,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $team,
        'title' => $title,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** Polls a single composer's rendered text until it matches $expected or times out. */
function chatPollComposerText(AwaitableWebpage $page, string $selectorJson, string $expected): ?string
{
    $expectedJson = json_encode($expected);

    return $page->script(<<<JS
        (async () => {
            const start = Date.now();
            let last = null;
            while (Date.now() - start < 5000) {
                const el = document.querySelector({$selectorJson});
                last = el ? el.textContent.trim() : null;
                if (last === {$expectedJson}) return last;
                await new Promise((r) => setTimeout(r, 50));
            }
            return last;
        })();
    JS);
}

/** Polls localStorage for a key to appear (the debounced saveDraft write lands). */
function chatPollDraftWritten(AwaitableWebpage $page, string $keyJson): ?string
{
    return $page->script(<<<JS
        (async () => {
            const start = Date.now();
            while (Date.now() - start < 5000) {
                const raw = localStorage.getItem({$keyJson});
                if (raw) return raw;
                await new Promise((r) => setTimeout(r, 50));
            }
            return null;
        })();
    JS);
}

/** Polls the last user message's sendState until it matches $target or times out. */
function chatPollSendState(AwaitableWebpage $page, string $resolveInterface, string $target): ?string
{
    $state = null;
    for ($i = 0; $i < 60; $i++) {
        $state = $page->script(<<<JS
            (() => {
                {$resolveInterface}
                return data.messages.find((m) => m.role === 'user')?.sendState ?? null;
            })();
        JS);
        if ($state === $target) {
            return $state;
        }
        usleep(100_000);
    }

    return $state;
}

it('restores a typed draft into the composer after a reload', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    chatInsertConversation($conversationId, $user, $team->getKey());

    $editor = '[data-chat-context="conversation"] [contenteditable="true"]';
    $editorJson = json_encode($editor);
    $draftKeyJson = json_encode("chat.draft.{$conversationId}");

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats/{$conversationId}")
        ->assertSourceHas('placeholder="Ask anything..."');

    $page->click($editor)->type($editor, 'draft survives reload');

    $saved = chatPollDraftWritten($page, $draftKeyJson);
    expect($saved)->not->toBeNull();

    $page->refresh()->assertSourceHas('placeholder="Ask anything..."');

    $restored = chatPollComposerText($page, $editorJson, 'draft survives reload');
    expect($restored)->toBe('draft survives reload');
});

it('keeps drafts scoped per conversation when switching between chats', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationA = (string) Str::uuid7();
    $conversationB = (string) Str::uuid7();
    chatInsertConversation($conversationA, $user, $team->getKey(), 'chat a');
    chatInsertConversation($conversationB, $user, $team->getKey(), 'chat b');

    $editor = '[data-chat-context="conversation"] [contenteditable="true"]';
    $editorJson = json_encode($editor);
    $draftAKeyJson = json_encode("chat.draft.{$conversationA}");

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats/{$conversationA}")
        ->assertSourceHas('placeholder="Ask anything..."');

    $page->click($editor)->type($editor, 'draft for chat a');

    $saved = chatPollDraftWritten($page, $draftAKeyJson);
    expect($saved)->not->toBeNull();

    $page->navigate("/app/{$team->slug}/chats/{$conversationB}")
        ->assertSourceHas('placeholder="Ask anything..."');

    $emptyInB = chatPollComposerText($page, $editorJson, '');
    expect($emptyInB)->toBe('');

    $page->navigate("/app/{$team->slug}/chats/{$conversationA}")
        ->assertSourceHas('placeholder="Ask anything..."');

    $restoredInA = chatPollComposerText($page, $editorJson, 'draft for chat a');
    expect($restoredInA)->toBe('draft for chat a');
});

it('clears the draft once the message is actually sent', function (): void {
    Queue::fake();

    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    chatInsertConversation($conversationId, $user, $team->getKey());

    $editorJson = json_encode('[data-chat-context="conversation"] [contenteditable="true"]');
    $draftKeyJson = json_encode("chat.draft.{$conversationId}");

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats/{$conversationId}")
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();

    $page->script(<<<JS
        (() => {
            {$resolveInterface}
            window.Echo = null;
            data.localEditor().setText('hello, then cleared');
            return true;
        })();
    JS);

    $saved = chatPollDraftWritten($page, $draftKeyJson);
    expect($saved)->not->toBeNull();

    // Widened timeout: this fires the real /chat/send/{id} round trip through
    // the in-process server, which the file docblock explains needs headroom
    // above the 1s default per-attempt budget on this box.
    Playwright::usingTimeout(60_000, fn (): mixed => $page->script(<<<JS
        (async () => {
            {$resolveInterface}
            await data.sendMessage();
            return true;
        })();
    JS));

    $finalState = chatPollSendState($page, $resolveInterface, 'sent');
    expect($finalState)->toBe('sent');

    $afterSend = $page->script(<<<JS
        (() => localStorage.getItem({$draftKeyJson}))();
    JS);
    expect($afterSend)->toBeNull();

    $page->refresh()->assertSourceHas('placeholder="Ask anything..."');

    $composerText = chatPollComposerText($page, $editorJson, '');
    expect($composerText)->toBe('');
});

it('clears the new-conversation draft bucket once the first message creates the conversation', function (): void {
    Queue::fake();

    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();

    // The composer works before any conversation exists (conversationId is
    // still null here): typing must bucket the draft under the literal
    // 'new' key segment, not a resolved id.
    $page->script(<<<JS
        (() => {
            {$resolveInterface}
            window.Echo = null;
            data.localEditor().setText('first message ever');
            return data.conversationId;
        })();
    JS);

    $saved = chatPollDraftWritten($page, json_encode('chat.draft.new'));
    expect($saved)->not->toBeNull();

    // Widened timeout: this chains a real create-conversation POST plus a
    // real send POST through the in-process server (the isFirstMessage
    // branch), well above the 1s default per-attempt budget on this box.
    Playwright::usingTimeout(60_000, fn (): mixed => $page->script(<<<JS
        (async () => {
            {$resolveInterface}
            await data.sendMessage();
            return true;
        })();
    JS));

    // Poll all the way to the terminal 'sent' state before inspecting
    // localStorage: conversationId is assigned midway through deliverMessage,
    // well before clearDraft() runs at the very end, so a shallower poll on
    // conversationId alone would race the assertion below against the rest
    // of that async function.
    $finalState = chatPollSendState($page, $resolveInterface, 'sent');
    expect($finalState)->toBe('sent');

    $newConversationId = $page->script(<<<JS
        (() => {
            {$resolveInterface}
            return data.conversationId;
        })();
    JS);
    expect($newConversationId)->not->toBeNull();

    // The 'new' bucket that funded this exact send must not survive it: a
    // later fresh "new chat" composer would otherwise restore a message
    // that was already sent and now lives in a real conversation.
    $leftoverNewDraft = $page->script(<<<'JS'
        (() => localStorage.getItem('chat.draft.new'))();
    JS);
    expect($leftoverNewDraft)->toBeNull();
});
