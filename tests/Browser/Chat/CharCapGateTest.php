<?php

declare(strict_types=1);

use App\Models\User;

it('does not send when input exceeds the character cap and Enter is pressed', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}/companies")
        ->navigate("/app/{$team->slug}/chats")
        ->assertSourceHas('placeholder="Ask anything..."');

    $page->script(<<<'JS'
        (() => {
            const candidates = Array.from(document.querySelectorAll('[x-data^="chatInterface"]'));
            const visible = candidates.find((el) => el.offsetParent !== null) ?? candidates[0];
            window.__charCapHost = visible;
            const textarea = visible.querySelector('#chat-message-input');
            textarea.value = 'x'.repeat(5100);
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
            Alpine.$data(visible).input = 'x'.repeat(5100);
            return true;
        })();
    JS);

    $messageCountBefore = (int) $page->script(<<<'JS'
        (() => Alpine.$data(window.__charCapHost).messages.length)()
    JS);

    $page->script(<<<'JS'
        (() => {
            const textarea = window.__charCapHost.querySelector('#chat-message-input');
            textarea.focus();
            textarea.dispatchEvent(new KeyboardEvent('keydown', {
                key: 'Enter',
                code: 'Enter',
                keyCode: 13,
                which: 13,
                bubbles: true,
                cancelable: true,
            }));
            return true;
        })();
    JS);

    $messageCountAfter = (int) $page->script(<<<'JS'
        (() => Alpine.$data(window.__charCapHost).messages.length)()
    JS);

    expect($messageCountBefore)->toBe(0);
    expect($messageCountAfter)->toBe($messageCountBefore);
});
