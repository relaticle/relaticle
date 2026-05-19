<?php

declare(strict_types=1);

use App\Models\User;

it('does not push two user messages when sendMessage is called twice in the same tick', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}/companies")
        ->navigate("/app/{$team->slug}/chats")
        ->assertSourceHas('placeholder="Ask anything..."');

    $userCount = (int) $page->script(<<<'JS'
        (async () => {
            const candidates = Array.from(document.querySelectorAll('[x-data^="chatInterface"]'));
            const visible = candidates.find((el) => el.offsetParent !== null) ?? candidates[0];
            const data = Alpine.$data(visible);
            data.input = 'race test';

            // Stub fetch so the test never hits the real /chat endpoint, but keep
            // sendMessage's flow up through the await on subscribeToConversation.
            window.fetch = () => new Promise(() => {});

            const a = data.sendMessage();
            const b = data.sendMessage();

            await Promise.resolve();
            await new Promise((r) => setTimeout(r, 50));

            return data.messages.filter((m) => m.role === 'user').length;
        })();
    JS);

    expect($userCount)->toBe(1);
});
