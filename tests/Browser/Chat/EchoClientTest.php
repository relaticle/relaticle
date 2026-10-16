<?php

declare(strict_types=1);

use App\Models\User;
use Tests\Helpers\ChatBrowser;

it('builds the websocket client from the address the panel renders', function (): void {
    config(['filament.broadcasting.echo' => [
        'broadcaster' => 'reverb',
        'key' => 'runtime-key',
        'wsHost' => 'ws.example.test',
        'wsPort' => 443,
        'wssPort' => 443,
        'wsPath' => '/reverb',
        'forceTLS' => true,
        'enabledTransports' => ['ws', 'wss'],
    ]]);

    $user = User::factory()->withWorkspace()->create();

    ChatBrowser::logIn($user, $user->ownedWorkspaces()->first()->slug)
        ->assertScript('window.Echo.options.key', 'runtime-key')
        ->assertScript('window.Echo.options.wsHost', 'ws.example.test')
        ->assertScript('window.Echo.options.wsPath', '/reverb');
});

it('boots no websocket client when broadcasting is off', function (): void {
    config(['filament.broadcasting' => []]);

    $user = User::factory()->withWorkspace()->create();

    ChatBrowser::logIn($user, $user->ownedWorkspaces()->first()->slug)
        ->assertScript('window.Echo === undefined')
        ->assertNoJavaScriptErrors();
});
