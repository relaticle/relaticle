<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

mutates(EmailInboxPage::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'email_address' => 'ana@acme.test',
        'status' => 'active',
        'sync_cursor' => 'history-done',
    ]));

    config(['relaticle.features.email_conversations' => [$this->workspace->id]]);
});

function liveBrowserBroadcasting(): void
{
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
}

function liveBrowserCountRefreshes(): string
{
    return <<<'JS'
        (() => {
            window.__refreshCalls = []
            const fetch = window.fetch
            window.fetch = (...args) => {
                const body = String(args[1]?.body ?? '')
                if (body.includes('refreshConversations')) {
                    window.__refreshCalls.push(body)
                }
                return fetch(...args)
            }
            return true
        })()
        JS;
}

function liveBrowserSignal(string $workspaceId, string $key): string
{
    return sprintf(
        '(() => { const name = Object.keys(window.Echo.connector.channels).find((channel) => channel.startsWith("private-App.Models.User.")); window.Echo.connector.channels[name].subscription.emit("email.conversation.changed", { workspace_id: %s, conversation_key: %s }); return true })()',
        json_encode($workspaceId),
        json_encode($key),
    );
}

it('subscribes to the user channel on the emails page', function (string $theme): void {
    liveBrowserBroadcasting();

    loginViaBrowser($this->owner, $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->navigate("/app/{$this->workspace->slug}/email")
        ->assertPresent('[data-live-conversations]')
        ->assertScript("Object.keys(window.Echo.connector.channels).includes('private-App.Models.User.{$this->owner->id}')")
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->assertScript("Object.keys(window.Echo.connector.channels).includes('private-App.Models.User.{$this->owner->id}')");
})->with(['inLightMode', 'inDarkMode']);

it('refreshes once for a burst of signals', function (string $theme): void {
    liveBrowserBroadcasting();

    $page = loginViaBrowser($this->owner, $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->navigate("/app/{$this->workspace->slug}/email")
        ->assertPresent('[data-live-conversations]')
        ->assertScript(liveBrowserCountRefreshes());

    foreach (range(1, 5) as $index) {
        $page->assertScript(liveBrowserSignal($this->workspace->id, "conv-{$index}"));
    }

    $page->assertScript('window.__refreshCalls.length', 0)
        ->wait(1.5)
        ->assertScript('window.__refreshCalls.length', 1)
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);

it('ignores a signal for another workspace', function (string $theme): void {
    liveBrowserBroadcasting();

    loginViaBrowser($this->owner, $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->navigate("/app/{$this->workspace->slug}/email")
        ->assertPresent('[data-live-conversations]')
        ->assertScript(liveBrowserCountRefreshes())
        ->assertScript(liveBrowserSignal('another-workspace', 'conv-elsewhere'))
        ->wait(1.5)
        ->assertScript('window.__refreshCalls.length', 0);
})->with(['inLightMode', 'inDarkMode']);

it('boots without errors when broadcasting is off', function (string $theme): void {
    config(['filament.broadcasting' => []]);

    loginViaBrowser($this->owner, $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->navigate("/app/{$this->workspace->slug}/email")
        ->assertPresent('[data-live-conversations]')
        ->assertScript('window.Echo === undefined')
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);
