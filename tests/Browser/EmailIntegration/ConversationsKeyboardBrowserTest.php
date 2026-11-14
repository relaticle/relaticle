<?php

declare(strict_types=1);

use App\Livewire\App\Profile\UpdateKeyboardShortcuts;
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

mutates(EmailInboxPage::class, UpdateKeyboardShortcuts::class);

const KEYBOARD_BROWSER_VISIBLE = '[...document.querySelectorAll("[data-conversation]")].filter((row) => ! row.hidden).map((row) => row.dataset.conversation)';
const KEYBOARD_BROWSER_FOCUSED = 'document.activeElement.closest("[data-conversation]")?.dataset.conversation ?? null';
const KEYBOARD_BROWSER_OPEN_COMPOSERS = 'Livewire.all().filter((component) => component.$wire.isOpen === true).length';

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

    keyboardBrowserInbound($this->mailbox, 'conv-one', 'Pricing for 40 seats', now()->subMinutes(10));
    keyboardBrowserInbound($this->mailbox, 'conv-two', 'Security questionnaire', now()->subMinutes(20));
    keyboardBrowserInbound($this->mailbox, 'conv-three', 'Renewal terms', now()->subMinutes(30));
});

function keyboardBrowserInbound(ConnectedAccount $mailbox, string $key, string $subject, mixed $sentAt): void
{
    $email = Email::factory()->create([
        'workspace_id' => $mailbox->workspace_id,
        'user_id' => $mailbox->user_id,
        'connected_account_id' => $mailbox->id,
        'conversation_key' => $key,
        'subject' => $subject,
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

it('moves through conversations with j and k and marks done with e', function (string $theme): void {
    visitAs($this->owner, "/app/{$this->workspace->slug}", theme: $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/email?view=needs_reply")
        ->assertScript(KEYBOARD_BROWSER_VISIBLE, ['conv-one', 'conv-two', 'conv-three'])
        ->keys('[data-conversation="conv-one"] > button', 'j')
        ->assertScript(KEYBOARD_BROWSER_FOCUSED, 'conv-two')
        ->keys(':focus', 'k')
        ->assertScript(KEYBOARD_BROWSER_FOCUSED, 'conv-one')
        ->keys(':focus', 'j')
        ->keys(':focus', 'e')
        ->wait(1.5)
        ->assertScript(KEYBOARD_BROWSER_VISIBLE, ['conv-one', 'conv-three'])
        ->assertScript(KEYBOARD_BROWSER_FOCUSED, 'conv-three')
        ->assertNoJavaScriptErrors();

    expect(EmailConversationState::query()->pluck('conversation_key')->all())->toBe(['conv-two']);
})->with(['inLightMode', 'inDarkMode']);

it('does not fire shortcuts while typing in the search field or the reply editor', function (string $theme): void {
    $search = '[data-conversation-search] input';
    $editor = '[data-inline-composer] [contenteditable="true"]';

    $page = visitAs($this->owner, "/app/{$this->workspace->slug}", theme: $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/email?view=needs_reply")
        ->click($search)
        ->keys($search, ['j', 'e', 'u'])
        ->assertValue($search, 'jeu')
        ->assertScript('document.activeElement.matches("[data-conversation-search] input")', true)
        ->assertScript(KEYBOARD_BROWSER_VISIBLE, ['conv-one', 'conv-two', 'conv-three']);

    $page->script('document.querySelector(\'[data-conversation="conv-one"] > button\').click()');

    $page->assertSeeIn('#email-reader-heading', 'Pricing for 40 seats')
        ->click('[data-reader-action="reply"]')
        ->click($editor)
        ->keys($editor, ['j', 'e', 'u'])
        ->wait(1)
        ->assertSeeIn($editor, 'jeu')
        ->assertSeeIn('#email-reader-heading', 'Pricing for 40 seats')
        ->assertNoJavaScriptErrors();

    expect(EmailConversationState::query()->count())->toBe(0);
})->with(['inLightMode', 'inDarkMode']);

it('disables letter keys but keeps modified and named keys when single-key shortcuts are off', function (string $theme): void {
    $this->owner->forceFill(['single_key_shortcuts' => false])->save();
    $heading = '#email-reader-heading';

    $page = visitAs($this->owner, "/app/{$this->workspace->slug}", theme: $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/email?view=needs_reply")
        ->assertScript('Alpine.store("shortcuts").singleKeys', false)
        ->click('[data-conversation="conv-one"] > button')
        ->assertSeeIn($heading, 'Pricing for 40 seats')
        ->keys($heading, ['j', 'e', 'c', '?'])
        ->wait(1)
        ->assertSeeIn($heading, 'Pricing for 40 seats')
        ->assertMissing('[data-shortcut-sheet] [role="dialog"]')
        ->assertScript(KEYBOARD_BROWSER_OPEN_COMPOSERS, 0)
        ->keys($heading, 'Escape')
        ->assertMissing('.fi-email-reader-drawer')
        ->wait(1)
        ->keys('[data-conversation="conv-two"] > button', 'Enter')
        ->assertSeeIn($heading, 'Security questionnaire');

    $page->keys($heading, 'Escape')
        ->assertMissing('.fi-email-reader-drawer')
        ->assertScript('(() => { const event = new KeyboardEvent("keydown", { key: "k", code: "KeyK", ctrlKey: true, bubbles: true, cancelable: true }); document.activeElement.dispatchEvent(event); return event.defaultPrevented })()', false)
        ->assertMissing('.fi-email-reader-drawer')
        ->assertNoJavaScriptErrors();

    expect(EmailConversationState::query()->count())->toBe(0);
})->with(['inLightMode', 'inDarkMode']);

it('undoes done with z while the toast is visible', function (string $theme): void {
    visitAs($this->owner, "/app/{$this->workspace->slug}", theme: $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/email?view=needs_reply")
        ->keys('[data-conversation="conv-one"] > button', 'e')
        ->wait(1.5)
        ->assertScript(KEYBOARD_BROWSER_VISIBLE, ['conv-two', 'conv-three'])
        ->assertVisible('[data-undo-action]')
        ->assertAttribute('[data-undo-action]', 'aria-keyshortcuts', 'Z')
        ->keys('[data-conversation="conv-two"] > button', 'z')
        ->wait(1.5)
        ->assertScript(KEYBOARD_BROWSER_VISIBLE, ['conv-one', 'conv-two', 'conv-three'])
        ->assertNoJavaScriptErrors();

    expect(EmailConversationState::query()->count())->toBe(0);
})->with(['inLightMode', 'inDarkMode']);

it('lists the shortcuts in the help sheet', function (string $theme): void {
    $sheet = '[data-shortcut-sheet] [role="dialog"]';

    $page = visitAs($this->owner, "/app/{$this->workspace->slug}", theme: $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/email?view=needs_reply")
        ->keys('[data-conversation="conv-one"] > button', '?')
        ->assertVisible($sheet)
        ->assertScript('[...document.querySelectorAll("[data-shortcut-sheet] dt")].map((label) => label.textContent).filter((label) => ["Next conversation", "Select conversation", "Undo"].includes(label)).length', 4)
        ->assertSeeIn($sheet, 'Single-key shortcuts are on.');

    $page->screenshot(filename: "shortcut-sheet-{$theme}");

    $page->assertScript('document.activeElement.closest("[data-shortcut-sheet] [role=dialog]") !== null', true)
        ->keys(':focus', 'Escape')
        ->assertMissing($sheet)
        ->assertScript(KEYBOARD_BROWSER_FOCUSED, 'conv-one')
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);

it('turns single-character keys off from the profile page without a reload', function (string $theme): void {
    $page = visitAs($this->owner, "/app/{$this->workspace->slug}", theme: $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->navigate("/app/{$this->workspace->slug}/settings/profile")
        ->assertScript('Alpine.store("shortcuts").singleKeys', true)
        ->assertSee('Single-key shortcuts');

    $page->script('window.keyboardBrowserMarker = true; window.keyboardBrowserWarnings = []; const warn = console.warn; console.warn = (...args) => { window.keyboardBrowserWarnings.push(String(args[0])); warn.apply(console, args) }');

    $page->click('button[role="switch"]')
        ->assertSee('Keyboard shortcuts saved.')
        ->assertScript('window.keyboardBrowserMarker', true)
        ->assertScript('Alpine.store("shortcuts").singleKeys', false)
        ->assertScript('Alpine.store("shortcuts").keysFor("next")', null)
        ->assertScript("[...(window.__pestBrowser?.jsErrors || []).map((error) => error.message), ...window.keyboardBrowserWarnings.filter((warning) => /Expression Error/.test(warning))].filter((message) => ! /collapsedGroups|reading 'includes'/.test(message)).join(' | ')", '');

    expect($this->owner->refresh()->single_key_shortcuts)->toBeFalse();
})->with(['inLightMode', 'inDarkMode']);
