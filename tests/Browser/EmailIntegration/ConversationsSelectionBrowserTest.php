<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailConversationState;

mutates(EmailInboxPage::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->mailbox = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'email_address' => 'ana@acme.test',
        'status' => 'active',
        'sync_cursor' => 'history-done',
    ]));

    config(['relaticle.features.email_conversations' => [$this->workspace->id]]);
});

it('keeps the selection bar over the list when the sidebar collapses', function (string $theme): void {
    triageBrowserInbound($this->mailbox, 'conv-one', now()->subMinutes(10));
    $edges = '(() => { const wrapper = document.querySelector("[data-triage-bar]").parentElement; const list = document.querySelector("[data-select-page]").closest("[x-data]"); return Math.abs(wrapper.getBoundingClientRect().left - list.getBoundingClientRect().left) < 1 })()';

    visitAs($this->owner, "/app/{$this->workspace->slug}", theme: $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/email?view=needs_reply")
        ->click('[data-conversation="conv-one"] [data-select-conversation]')
        ->assertVisible('[data-triage-bar]')
        ->assertScript($edges, true)
        ->assertScript('document.querySelector("[data-select-page]").closest("[x-data]").getBoundingClientRect().left > 100', true)
        ->assertScript('Alpine.store("sidebar").close() || true')
        ->wait(1)
        ->assertScript('document.querySelector("[data-select-page]").closest("[x-data]").getBoundingClientRect().left < 100', true)
        ->assertScript($edges, true)
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);

it('says the selection is already done when a bulk done changes nothing', function (string $theme): void {
    triageBrowserInbound($this->mailbox, 'conv-one', now()->subMinutes(10));
    triageBrowserInbound($this->mailbox, 'conv-two', now()->subMinutes(20));
    foreach (['conv-one', 'conv-two'] as $key) {
        EmailConversationState::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'conversation_key' => $key]);
    }

    visitAs($this->owner, "/app/{$this->workspace->slug}", theme: $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->navigate("/app/{$this->workspace->slug}/email?view=all")
        ->click('[data-conversation="conv-one"] [data-select-conversation]')
        ->click('[data-conversation="conv-two"] [data-select-conversation]')
        ->keys('[data-conversation="conv-two"] [data-select-conversation]', 'e')
        ->assertSee('2 conversations already marked Done.')
        ->assertMissing('[data-triage-bar]')
        ->assertMissing('[data-undo-action]')
        ->assertNoJavaScriptErrors();

    expect(EmailConversationState::query()->count())->toBe(2);
})->with(['inLightMode', 'inDarkMode']);

it('empties the search box on Escape and keeps the selection', function (string $theme): void {
    triageBrowserInbound($this->mailbox, 'conv-one', now()->subMinutes(10));
    $search = '[data-conversation-search] input';
    $subject = Email::query()->where('conversation_key', 'conv-one')->value('subject');

    visitAs($this->owner, "/app/{$this->workspace->slug}", theme: $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->navigate("/app/{$this->workspace->slug}/email?view=needs_reply")
        ->click('[data-conversation="conv-one"] [data-select-conversation]')
        ->assertVisible('[data-triage-bar]')
        ->type($search, $subject)
        ->wait(1.5)
        ->assertPresent('[data-conversation="conv-one"]')
        ->assertVisible('[data-triage-bar]')
        ->keys($search, 'Escape')
        ->wait(1)
        ->assertValue($search, '')
        ->assertVisible('[data-triage-bar]')
        ->assertScript('document.querySelectorAll("[data-selected=true]").length', 1)
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);

it('drops the selection after leaving the view and coming back', function (string $theme): void {
    triageBrowserInbound($this->mailbox, 'conv-one', now()->subMinutes(10));

    visitAs($this->owner, "/app/{$this->workspace->slug}", theme: $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->navigate("/app/{$this->workspace->slug}/email?view=needs_reply")
        ->click('[data-conversation="conv-one"] [data-select-conversation]')
        ->assertVisible('[data-triage-bar]')
        ->assertScript("document.querySelector(`[wire\\\\:click=\"setView('all')\"]`).click() || true")
        ->wait(1.5)
        ->assertPresent('[data-conversation="conv-one"]')
        ->assertScript('document.querySelectorAll("[data-selected=true]").length', 0)
        ->assertScript("document.querySelector(`[wire\\\\:click=\"setView('needs_reply')\"]`).click() || true")
        ->wait(1.5)
        ->assertPresent('[data-conversation="conv-one"]')
        ->assertScript('document.querySelectorAll("[data-selected=true]").length', 0)
        ->assertMissing('[data-triage-bar]')
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);

it('drops the selection after paging away and back', function (string $theme): void {
    foreach (range(1, 51) as $index) {
        triageBrowserInbound($this->mailbox, "conv-{$index}", now()->subMinutes($index));
    }

    visitAs($this->owner, "/app/{$this->workspace->slug}", theme: $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->navigate("/app/{$this->workspace->slug}/email?view=needs_reply")
        ->click('[data-conversation="conv-1"] [data-select-conversation]')
        ->assertVisible('[data-triage-bar]')
        ->click('[data-conversations-page=older]')
        ->wait(1.5)
        ->assertPresent('[data-conversation="conv-51"]')
        ->click('[data-conversations-page=newer]')
        ->wait(1.5)
        ->assertPresent('[data-conversation="conv-1"]')
        ->assertScript('document.querySelectorAll("[data-selected=true]").length', 0)
        ->assertMissing('[data-triage-bar]')
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);
