<?php

declare(strict_types=1);

use App\Models\User;

it('dashboard does not embed legacy ?message= URL anymore', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $this->actingAs($user);

    $response = $this->get("/app/{$team->slug}");
    $response->assertOk();

    $response->assertDontSee("searchParams.set('message'", false);

    // The dashboard's submit() POSTs to the resolved chat.conversations.create
    // URL. We assert the path appears in the rendered output (with escaped slashes,
    // matching how Filament's wire:effects script attribute double-encodes the value)
    // so that renaming or removing the route causes the assertion to fail.
    $createPath = parse_url(route('chat.conversations.create'), PHP_URL_PATH);
    $escapedPath = str_replace('/', '\\\\\\/', $createPath);
    $response->assertSee($escapedPath, false);
    // The legacy submit() built a URL with `new URL(chatUrl, ...)`. The new
    // submit() POSTs to chat.conversations.create instead. Catch regression:
    $response->assertDontSee('new URL(chatUrl', false);
});

it('chat conversation page no longer reads ?message= query param', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $this->actingAs($user);

    $response = $this->get("/app/{$team->slug}/chats?message=hello&model=claude-opus");
    $response->assertOk();

    $response->assertDontSee('hello');
});
