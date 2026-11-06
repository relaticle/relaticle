<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailConversationState;
use Relaticle\EmailIntegration\Models\EmailParticipant;

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

function triageBrowserInbound(ConnectedAccount $mailbox, string $key, mixed $sentAt): void
{
    $email = Email::factory()->create([
        'workspace_id' => $mailbox->workspace_id,
        'user_id' => $mailbox->user_id,
        'connected_account_id' => $mailbox->id,
        'conversation_key' => $key,
        'rfc_message_id' => '<'.Str::lower((string) Str::ulid()).'@mail.test>',
        'direction' => EmailDirection::INBOUND,
        'status' => EmailStatus::SYNCED,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'is_internal' => false,
        'sent_at' => $sentAt,
    ]);

    EmailParticipant::factory()->from()->create(['email_id' => $email->id, 'email_address' => 'brian@airbnb.test', 'name' => 'Brian']);

    resolve(RefreshConversationSummary::class)->execute($mailbox->workspace_id, $key, broadcast: false);
}

it('shows a done row again when new mail brings it back on a refetch', function (string $theme): void {
    triageBrowserInbound($this->mailbox, 'conv-back', now()->subHour());
    $row = '[data-conversation="conv-back"]';

    $page = loginViaBrowser($this->owner, $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->navigate("/app/{$this->workspace->slug}/email?view=needs_reply")
        ->assertPresent($row)
        ->assertScript("document.querySelector('{$row} [data-triage-action=markDone]').click() || true")
        ->wait(1.5)
        ->assertScript("document.querySelector('{$row}').hidden", true);

    triageBrowserInbound($this->mailbox, 'conv-back', now());

    $page->assertScript("Livewire.find(document.querySelector('{$row}').closest('[wire\\\\:id]').getAttribute('wire:id')).call('refreshConversations').then(() => true)")
        ->wait(1.5)
        ->assertScript("document.querySelector('{$row}').hidden", false)
        ->assertScript("document.querySelector('{$row}').dataset.done", 'false')
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);

it('does nothing on o when single-key shortcuts are off and still opens the focused row on Enter', function (string $theme): void {
    $this->owner->forceFill(['single_key_shortcuts' => false])->save();
    triageBrowserInbound($this->mailbox, 'conv-off', now()->subHour());
    $button = '[data-conversation="conv-off"] > button';

    loginViaBrowser($this->owner, $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->navigate("/app/{$this->workspace->slug}/email?view=all")
        ->assertPresent($button)
        ->assertScript('Alpine.store("shortcuts").singleKeys', false)
        ->keys($button, 'o')
        ->wait(1)
        ->assertMissing('.fi-email-reader-drawer')
        ->keys($button, 'Enter')
        ->assertVisible('.fi-email-reader-drawer')
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);

it('marks done on Enter from the Done pill without opening the reader', function (string $theme): void {
    triageBrowserInbound($this->mailbox, 'conv-pill', now()->subHour());
    $row = '[data-conversation="conv-pill"]';

    loginViaBrowser($this->owner, $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->navigate("/app/{$this->workspace->slug}/email?view=all")
        ->assertPresent($row)
        ->assertScript("document.querySelector('{$row} > button').focus() || true")
        ->keys("{$row} [data-triage-action=markDone]", 'Enter')
        ->wait(1.5)
        ->assertMissing('.fi-email-reader-drawer')
        ->assertScript("document.querySelector('{$row}').dataset.done", 'true')
        ->assertNoJavaScriptErrors();

    expect(EmailConversationState::query()->where('user_id', $this->owner->id)->where('conversation_key', 'conv-pill')->exists())->toBeTrue();
})->with(['inLightMode', 'inDarkMode']);

it('keeps the reader open on Escape while one of its dropdowns is open', function (string $theme): void {
    triageBrowserInbound($this->mailbox, 'conv-menu', now()->subHour());

    $page = loginViaBrowser($this->owner, $theme)
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

it('moves the tab stop to a visible row when e marks the hovered tab stop done', function (string $theme): void {
    triageBrowserInbound($this->mailbox, 'conv-top', now()->subMinutes(10));
    triageBrowserInbound($this->mailbox, 'conv-below', now()->subHour());
    $tabStops = '[...document.querySelectorAll("[data-conversation]")].filter((row) => ! row.hidden && row.querySelector(":scope > button").tabIndex === 0).map((row) => row.dataset.conversation)';

    loginViaBrowser($this->owner, $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/email?view=needs_reply")
        ->assertPresent('[data-conversation="conv-below"]')
        ->assertScript($tabStops, ['conv-top'])
        ->hover('[data-conversation="conv-top"]')
        ->keys('[data-reveal-active-tab]', 'e')
        ->wait(1.5)
        ->assertScript('document.querySelector(\'[data-conversation="conv-top"]\').hidden', true)
        ->assertScript($tabStops, ['conv-below'])
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);
