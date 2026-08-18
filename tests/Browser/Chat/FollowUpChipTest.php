<?php

declare(strict_types=1);

use App\Models\User;
use Tests\Helpers\ChatBrowser;

/**
 * Follow-up chips arrive over the `.follow_ups` broadcast and render below the
 * assistant bubble. Clicking one must send its prompt as a new user message.
 * sendMessage() reads the composer via localEditor().getText(), so the chip
 * handler has to write the prompt into the TipTap editor — setting the Alpine
 * `input` mirror alone is silently dropped (a mounted empty editor returns ''
 * which short-circuits the `?? this.input` fallback).
 */
it('sends the follow-up prompt as a user message when a chip is clicked', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats")
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();

    $result = json_decode((string) $page->script(<<<JS
        (async () => {
            {$resolveInterface}

            // A finished assistant turn whose follow-up chips came in over the
            // .follow_ups broadcast.
            data.messages.push({
                role: 'assistant',
                content: 'Here are the companies added this week.',
                rendered: true,
                prerendered: false,
                editing: false,
                editText: '',
                copiedAt: 0,
                follow_ups: [{ label: 'Filter by industry', prompt: 'Filter these companies by industry' }],
            });

            await new Promise((r) => setTimeout(r, 50));

            const chip = Array.from(host.querySelectorAll('button'))
                .find((b) => b.textContent.trim() === 'Filter by industry');
            if (! chip) {
                return JSON.stringify({ error: 'chip not rendered' });
            }

            // Keep sendMessage() away from the real /chat endpoint; the
            // optimistic user-message push happens before the fetch.
            window.fetch = () => new Promise(() => {});

            chip.click();

            await new Promise((r) => setTimeout(r, 100));

            return JSON.stringify({
                userMessages: data.messages
                    .filter((m) => m.role === 'user')
                    .map((m) => m.content),
            });
        })();
    JS), true, 512, JSON_THROW_ON_ERROR);

    expect($result)->not->toHaveKey('error')
        ->and($result['userMessages'])->toBe(['Filter these companies by industry']);
});
