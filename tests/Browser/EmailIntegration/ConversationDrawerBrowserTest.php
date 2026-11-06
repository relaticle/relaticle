<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CompanyResource\RelationManagers\EmailsRelationManager;
use App\Filament\Resources\CompanyResource\RelationManagers\NotesRelationManager;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;

mutates(EmailInboxPage::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->teammate = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($this->teammate, ['role' => 'member']);
    $this->ownerMailbox = drawerBrowserMailbox($this->owner, 'ana@acme.test');
    $this->teammateMailbox = drawerBrowserMailbox($this->teammate, 'ben@acme.test');
    $this->company = Company::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Airbnb']);

    config(['relaticle.features.email_conversations' => [$this->workspace->id]]);
});

function drawerBrowserMailbox(User $user, string $address): ConnectedAccount
{
    return ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $user->current_workspace_id,
        'user_id' => $user->id,
        'email_address' => $address,
        'status' => 'active',
        'sync_cursor' => 'history-done',
    ]));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function drawerBrowserMessage(ConnectedAccount $mailbox, string $key, string $from, array $attributes = []): Email
{
    $email = Email::factory()->create([
        'workspace_id' => $mailbox->workspace_id,
        'user_id' => $mailbox->user_id,
        'connected_account_id' => $mailbox->id,
        'conversation_key' => $key,
        'rfc_message_id' => '<'.Str::lower((string) Str::ulid()).'@mail.test>',
        'direction' => $from === $mailbox->email_address ? EmailDirection::OUTBOUND : EmailDirection::INBOUND,
        'status' => EmailStatus::SYNCED,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'is_internal' => false,
        'sent_at' => now(),
        ...$attributes,
    ]);

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->id,
        'email_address' => $from,
        'name' => Str::headline(Str::before($from, '@')),
    ]);

    resolve(RefreshConversationSummary::class)->execute($mailbox->workspace_id, $key);

    return $email;
}

it('reads a conversation beside a live list and closes with Escape', function (string $theme): void {
    $first = drawerBrowserMessage($this->ownerMailbox, 'conv-first', 'brian@airbnb.test', ['subject' => 'Pricing for 40 seats']);
    drawerBrowserMessage($this->ownerMailbox, 'conv-second', 'kim@notion.test', ['subject' => 'Security questionnaire', 'sent_at' => now()->subHour()]);

    $page = loginViaBrowser($this->owner, $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->assertScript('document.documentElement.classList.contains("dark")', $theme === 'inDarkMode')
        ->navigate("/app/{$this->workspace->slug}/email?view=all")
        ->resize(1440, 900)
        ->click('[data-conversation="conv-first"] > button')
        ->assertVisible('.fi-email-reader-drawer')
        ->assertMissing('.fi-email-reader-backdrop')
        ->assertScript('new URL(location.href).searchParams.get("email")', $first->id)
        ->assertScript('document.activeElement.id', 'email-reader-heading')
        ->assertAttribute('.fi-email-reader-drawer [role="separator"]', 'aria-valuetext', '55%');

    $page->screenshot(filename: "conversation-drawer-{$theme}");

    $page->script('document.querySelector(\'[data-conversation="conv-second"] > button\').click()');

    $page->assertSee('Security questionnaire')
        ->keys('#email-reader-heading', 'Escape')
        ->assertMissing('.fi-email-reader-drawer')
        ->assertScript('document.activeElement.closest("[data-conversation]")?.dataset.conversation', 'conv-second')
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);

it('lands a resolver link on the company tab with exactly one reader open', function (string $theme): void {
    $email = drawerBrowserMessage($this->ownerMailbox, 'conv-linked', 'brian@airbnb.test', ['subject' => 'Renewal terms']);
    $this->company->emails()->attach($email->id);

    loginViaBrowser($this->owner, $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->assertScript('document.documentElement.classList.contains("dark")', $theme === 'inDarkMode')
        ->navigate(route('email.open', $email, absolute: false))
        ->assertPathIs("/app/{$this->workspace->slug}/companies/{$this->company->id}")
        ->assertSee('Renewal terms')
        ->assertScript('document.querySelectorAll(".fi-email-reader-drawer, .fi-email-reader-panel").length', 1)
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);

it('folds the record details away while the drawer is open so a second row stays clickable', function (string $theme): void {
    foreach (['conv-first' => 'Pricing for 40 seats', 'conv-second' => 'Security questionnaire'] as $key => $subject) {
        $email = drawerBrowserMessage($this->ownerMailbox, $key, 'brian@airbnb.test', ['subject' => $subject, 'sent_at' => $key === 'conv-first' ? now() : now()->subHour()]);
        $this->company->emails()->attach($email->id);
    }
    $emails = (string) array_search(EmailsRelationManager::class, CompanyResource::getRelations(), true);

    $page = loginViaBrowser($this->owner, $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->assertScript('document.documentElement.classList.contains("dark")', $theme === 'inDarkMode')
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies/{$this->company->id}?relation={$emails}")
        ->assertVisible('.fi-record-rail')
        ->click('[data-conversation="conv-first"] > button')
        ->assertSeeIn('#email-reader-heading', 'Pricing for 40 seats')
        ->assertMissing('.fi-record-rail')
        ->assertMissing('.fi-record-rail-resize-handle')
        ->assertScript('Math.round(document.querySelector(".fi-email-reader-drawer").getBoundingClientRect().width) === Math.round(window.innerWidth * 0.55)', true);

    $page->page()->locator('[data-conversation="conv-second"] > button')->click(['position' => ['x' => 40, 'y' => 20]]);

    $page->assertSeeIn('#email-reader-heading', 'Security questionnaire')
        ->assertMissing('.fi-record-rail')
        ->click('.fi-email-reader-drawer button[aria-label="'.__('filament/emails/composer.actions.close').'"]')
        ->assertMissing('.fi-email-reader-drawer')
        ->assertVisible('.fi-record-rail')
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);

it('opens one reader when a record page switches from another tab to Emails', function (string $theme): void {
    $email = drawerBrowserMessage($this->ownerMailbox, 'conv-tabs', 'brian@airbnb.test', ['subject' => 'Renewal terms']);
    $this->company->emails()->attach($email->id);
    $notes = (string) array_search(NotesRelationManager::class, CompanyResource::getRelations(), true);
    $emails = (string) array_search(EmailsRelationManager::class, CompanyResource::getRelations(), true);

    loginViaBrowser($this->owner, $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->assertScript('document.documentElement.classList.contains("dark")', $theme === 'inDarkMode')
        ->navigate("/app/{$this->workspace->slug}/companies/{$this->company->id}?relation={$notes}&email={$email->id}")
        ->assertScript('document.querySelectorAll(".fi-email-reader-drawer, .fi-email-reader-panel").length', 0)
        ->click("[role=\"tab\"][data-tab-key=\"{$emails}\"]")
        ->assertSee('Renewal terms')
        ->assertScript('document.querySelectorAll(".fi-email-reader-drawer, .fi-email-reader-panel").length', 1)
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);

it('opens a locked row at the gate with Request access', function (string $theme): void {
    $email = drawerBrowserMessage($this->teammateMailbox, 'conv-locked', 'olivia@airbnb.test', [
        'subject' => 'Contract redlines',
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
    ]);
    $this->company->emails()->attach($email->id);

    $page = loginViaBrowser($this->owner, $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->assertScript('document.documentElement.classList.contains("dark")', $theme === 'inDarkMode')
        ->navigate("/app/{$this->workspace->slug}/email?view=all")
        ->click('[data-conversation="conv-locked"] > button')
        ->assertVisible('.fi-email-reader-drawer')
        ->assertDontSee('Contract redlines')
        ->assertSee(__('filament/pages/email-inbox.drawer.gate.request'));

    $page->screenshot(filename: "conversation-drawer-gate-{$theme}");
    $page->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);

it('fills the screen on a phone and saves a typed reply when it closes', function (string $theme): void {
    drawerBrowserMessage($this->ownerMailbox, 'conv-phone', 'brian@airbnb.test', ['subject' => 'Can we talk Monday']);

    $page = loginViaBrowser($this->owner, $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->assertScript('document.documentElement.classList.contains("dark")', $theme === 'inDarkMode')
        ->resize(390, 844)
        ->navigate("/app/{$this->workspace->slug}/email?view=all")
        ->click('[data-conversation="conv-phone"] > button')
        ->assertScript('Math.round(document.querySelector(".fi-email-reader-drawer").getBoundingClientRect().width) === window.innerWidth', true)
        ->assertScript('Math.round(document.querySelector(".fi-email-reader-drawer").getBoundingClientRect().height) === window.innerHeight', true);

    $page->screenshot(filename: "conversation-drawer-phone-{$theme}");

    $page->click('[data-reader-action="reply"]')
        ->type('[data-inline-composer] [contenteditable="true"]', 'Monday at 10 works')
        ->keys('[data-inline-composer] [contenteditable="true"]', 'Escape')
        ->keys('#email-reader-heading', 'Escape')
        ->assertMissing('.fi-email-reader-drawer')
        ->assertScript('new Promise((resolve) => { const poll = () => document.querySelector(".fi-email-reader-drawer") ? setTimeout(poll, 50) : resolve(true); poll() })', true)
        ->assertNoJavaScriptErrors();

    expect(Email::query()->where('user_id', $this->owner->id)->where('status', EmailStatus::DRAFT)->sole()->body?->body_text)
        ->toContain('Monday at 10 works');
})->with(['inLightMode', 'inDarkMode']);

it('saves a typed reply when Next opens another conversation and replies there', function (string $theme): void {
    drawerBrowserMessage($this->ownerMailbox, 'conv-first', 'brian@airbnb.test', ['subject' => 'Pricing for 40 seats']);
    drawerBrowserMessage($this->ownerMailbox, 'conv-second', 'kim@notion.test', ['subject' => 'Security questionnaire', 'sent_at' => now()->subHour()]);

    $page = loginViaBrowser($this->owner, $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->assertScript('document.documentElement.classList.contains("dark")', $theme === 'inDarkMode')
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/email?view=all")
        ->click('[data-conversation="conv-first"] > button')
        ->click('[data-reader-action="reply"]')
        ->type('[data-inline-composer] [contenteditable="true"]', 'Forty seats from March')
        ->click('[data-reader-action="next"]')
        ->assertSeeIn('#email-reader-heading', 'Security questionnaire');

    $page->script('document.querySelector(\'[data-reader-action="reply"]\').click()');

    $page->assertVisible('[data-inline-composer] [contenteditable="true"]')
        ->assertNoJavaScriptErrors();

    expect(Email::query()->where('user_id', $this->owner->id)->where('status', EmailStatus::DRAFT)->sole()->body?->body_text)
        ->toContain('Forty seats from March');
})->with(['inLightMode', 'inDarkMode']);
