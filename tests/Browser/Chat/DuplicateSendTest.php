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
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats")
        ->assertSourceHas('placeholder="Ask anything..."');

    $userCount = (int) $page->script(<<<'JS'
        (async () => {
            const host = Array.from(document.querySelectorAll('[x-data^="chatInterface"]'))
                .find((el) => el.offsetParent !== null);
            const data = Alpine.$data(host);

            // sendMessage() reads the composer via localEditor().getText().
            data.localEditor().setText('race test');

            // Stub fetch so the test never hits the real /chat endpoint, but keep
            // sendMessage's flow up through the await on conversation creation.
            window.fetch = () => new Promise(() => {});

            data.sendMessage();
            data.sendMessage();

            await Promise.resolve();
            await new Promise((r) => setTimeout(r, 50));

            return data.messages.filter((m) => m.role === 'user').length;
        })();
    JS);

    expect($userCount)->toBe(1);
});
