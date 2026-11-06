<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailReaderActions;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;

mutates(HasEmailReaderActions::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->company = Company::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Airbnb']);
    $mailbox = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'email_address' => 'ana@acme.test',
        'status' => 'active',
        'sync_cursor' => 'history-done',
    ]));

    $this->email = Email::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $mailbox->id,
        'conversation_key' => 'conv-airbnb',
        'rfc_message_id' => '<'.Str::lower((string) Str::ulid()).'@mail.test>',
        'direction' => EmailDirection::INBOUND,
        'status' => EmailStatus::SYNCED,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'subject' => 'Renewal terms',
        'sent_at' => now(),
    ]);
    EmailParticipant::factory()->from()->create(['email_id' => $this->email->id, 'email_address' => 'brian@airbnb.test', 'name' => 'Brian']);
    resolve(RefreshConversationSummary::class)->execute($this->workspace->id, 'conv-airbnb');

    config(['relaticle.features.email_conversations' => [$this->workspace->id]]);
});

function emailLinkSource(Email $email, Company $company): ?string
{
    $source = DB::table('emailables')
        ->where('email_id', $email->getKey())
        ->where('emailable_id', $company->getKey())
        ->value('link_source');

    return $source === null ? null : (string) $source;
}

it('links the open conversation with l and the record picker', function (string $theme): void {
    $page = loginViaBrowser($this->owner, $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->assertScript('document.documentElement.classList.contains("dark")', $theme === 'inDarkMode')
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/email?view=all")
        ->click('[data-conversation="conv-airbnb"] > button')
        ->assertSeeIn('#email-reader-heading', 'Renewal terms')
        ->keys('#email-reader-heading', 'l')
        ->assertSee('Link this conversation to a record')
        ->assertSee('Teammates will see this conversation on the record at your sharing level.');

    $page->click('.fi-modal-window .fi-select-input-btn')
        ->type('.fi-modal-window .fi-select-input-search-ctn input', 'airb')
        ->click('.fi-modal-window .fi-select-input-option')
        ->click('.fi-modal-window .fi-modal-footer-actions button[type="submit"]')
        ->assertSee('Linked to Airbnb')
        ->assertVisible('[data-email-reader] [data-linked-record="company:'.$this->company->id.'"]')
        ->assertNoJavaScriptErrors();

    expect(emailLinkSource($this->email, $this->company))->toBe('manual');
})->with(['inLightMode', 'inDarkMode']);

it('unlinks a chip from its keyboard menu and links it back from Undo', function (string $theme): void {
    $this->company->emails()->attach($this->email->id, ['link_source' => 'auto']);
    $chip = '[data-email-reader] [data-linked-record="company:'.$this->company->id.'"]';

    $page = loginViaBrowser($this->owner, $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->assertScript('document.documentElement.classList.contains("dark")', $theme === 'inDarkMode')
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/email?view=all")
        ->click('[data-conversation="conv-airbnb"] > button')
        ->assertVisible($chip);

    $trigger = "{$chip} button[aria-haspopup=\"menu\"]";

    $page->keys($trigger, 'Enter')
        ->assertVisible("{$chip} [data-unlink-record]")
        ->assertSeeIn($chip, 'Open record')
        ->click("{$chip} [data-unlink-record]")
        ->assertSee('Remove this conversation from Airbnb?')
        ->assertSee('It stays in your mail and will not be linked to Airbnb again automatically.')
        ->click('.fi-modal-window .fi-modal-footer-actions button[type="submit"]')
        ->assertSee('Removed from Airbnb')
        ->assertMissing($chip);

    expect(emailLinkSource($this->email, $this->company))->toBeNull();

    $page->click('.fi-no-notification .fi-link')
        ->assertSee('Linked to Airbnb')
        ->assertVisible($chip)
        ->assertNoJavaScriptErrors();

    expect(emailLinkSource($this->email, $this->company))->toBe('manual');
})->with(['inLightMode', 'inDarkMode']);
