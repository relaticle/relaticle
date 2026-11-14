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

it('keeps the reader open on Escape while one of its dropdowns is open', function (string $theme): void {
    triageBrowserInbound($this->mailbox, 'conv-menu', now()->subHour());

    $page = visitAs($this->owner, "/app/{$this->workspace->slug}", theme: $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/email?view=all")
        ->click('[data-conversation="conv-menu"] > button')
        ->assertVisible('.fi-email-reader-drawer')
        ->click('[data-reader-action="reply"]')
        ->assertVisible('[data-inline-composer] .fi-dropdown-trigger button[aria-label="Signature"]');

    $page->script("document.querySelector('[data-inline-composer] .fi-dropdown-trigger button[aria-label=Signature]').dispatchEvent(new MouseEvent('mousedown', { button: 0, bubbles: true }))");

    $page->assertScript("document.querySelector('[data-inline-composer] .fi-dropdown-trigger button[aria-label=Signature]').closest('.fi-dropdown').querySelector('.fi-dropdown-panel').style.display", 'block');

    $page->script("document.getElementById('email-reader-heading').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }))");

    $page->wait(1)
        ->assertVisible('.fi-email-reader-drawer')
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);

it('marks done from the reader a conversation the list does not show', function (string $theme): void {
    triageBrowserInbound($this->mailbox, 'conv-linked', now()->subHour());
    $email = Email::query()->where('conversation_key', 'conv-linked')->sole();

    visitAs($this->owner, "/app/{$this->workspace->slug}", theme: $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->navigate("/app/{$this->workspace->slug}/email?view=awaiting_reply&email={$email->id}")
        ->assertVisible('[data-email-reader] [data-reader-action=done]')
        ->assertMissing('[data-conversation="conv-linked"]')
        ->click('[data-email-reader] [data-reader-action=done]')
        ->wait(1.5)
        ->navigate("/app/{$this->workspace->slug}/email?view=done")
        ->assertPresent('[data-conversation="conv-linked"]')
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);

it('undoes a reopen from the reader of a conversation the list does not show', function (string $theme): void {
    triageBrowserInbound($this->mailbox, 'conv-linked', now()->subHour());
    $email = Email::query()->where('conversation_key', 'conv-linked')->sole();
    EmailConversationState::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'conversation_key' => 'conv-linked',
        'done_through' => $email->sent_at,
    ]);

    visitAs($this->owner, "/app/{$this->workspace->slug}", theme: $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->navigate("/app/{$this->workspace->slug}/email?view=awaiting_reply&email={$email->id}")
        ->assertMissing('[data-conversation="conv-linked"]')
        ->click('[data-email-reader] [data-reader-action=reopen]')
        ->assertVisible('[data-undo-action]')
        ->click('[data-undo-action]')
        ->wait(1.5)
        ->navigate("/app/{$this->workspace->slug}/email?view=done")
        ->assertPresent('[data-conversation="conv-linked"]')
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);

it('closes the reader on Escape from an empty search box', function (string $theme): void {
    triageBrowserInbound($this->mailbox, 'conv-one', now()->subMinutes(10));
    $search = '[data-conversation-search] input';

    visitAs($this->owner, "/app/{$this->workspace->slug}", theme: $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/email?view=needs_reply")
        ->click('[data-conversation="conv-one"] > button')
        ->assertVisible('[data-email-reader]')
        ->assertScript("document.querySelector('{$search}').focus() || true")
        ->keys($search, 'Escape')
        ->wait(1)
        ->assertMissing('[data-email-reader]')
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);

it('keeps the selection bar beside the reader while the reader is resized', function (string $theme): void {
    foreach (['conv-open' => 10, 'conv-one' => 20] as $key => $minutes) {
        triageBrowserInbound($this->mailbox, $key, now()->subMinutes($minutes));
    }
    $meets = '(() => { const bar = document.querySelector("[data-triage-bar]").parentElement.getBoundingClientRect(); const reader = document.querySelector("[data-email-reader]").getBoundingClientRect(); return Math.abs(bar.right - reader.left) < 2 })()';
    $separator = '[data-email-reader] [role=separator]';

    $page = visitAs($this->owner, "/app/{$this->workspace->slug}", theme: $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/email?view=needs_reply")
        ->click('[data-conversation="conv-open"] > button')
        ->assertVisible('[data-email-reader]')
        ->click('[data-conversation="conv-one"] [data-select-conversation]')
        ->assertVisible('[data-triage-bar]')
        ->assertScript($meets, true);

    $before = $page->script('document.querySelector("[data-email-reader]").getBoundingClientRect().left');

    $page->keys($separator, 'ArrowRight')
        ->keys($separator, 'ArrowRight')
        ->wait(1)
        ->assertScript("document.querySelector('[data-email-reader]').getBoundingClientRect().left > {$before} + 50", true)
        ->assertScript($meets, true)
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);
