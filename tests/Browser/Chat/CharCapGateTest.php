<?php

declare(strict_types=1);

use App\Models\User;

it('does not send when the composer text exceeds the character cap', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button[type="submit"]')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats")
        ->assertSourceHas('placeholder="Ask anything..."');

    // Load 5,100 characters into the TipTap composer (cap is 5,000) and ask the
    // chat interface to send. sendMessage() reads the editor text via
    // localEditor().getText() and must bail before pushing a user message.
    $userMessageCount = (int) $page->script(<<<'JS'
        (async () => {
            const host = Array.from(document.querySelectorAll('[x-data^="chatInterface"]'))
                .find((el) => el.offsetParent !== null);
            const data = Alpine.$data(host);

            data.localEditor().setText('x'.repeat(5100));
            data.sendMessage();

            await new Promise((r) => setTimeout(r, 50));

            return data.messages.filter((m) => m.role === 'user').length;
        })();
    JS);

    expect($userMessageCount)->toBe(0);
});
