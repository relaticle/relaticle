<?php

declare(strict_types=1);

use App\Features\EmailConversations;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Events\ConversationChanged;
use Relaticle\EmailIntegration\Filament\Concerns\HasConversationFilters;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBody;
use Relaticle\EmailIntegration\Models\EmailParticipant;

mutates(EmailInboxPage::class, HasConversationFilters::class);

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

    Feature::for($this->workspace)->activate(EmailConversations::class);
    Event::fake([ConversationChanged::class]);
});

function searchBrowserMessage(ConnectedAccount $mailbox, string $key, string $from, string $subject, string $body): Email
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
        'subject' => $subject,
        'sent_at' => now(),
    ]);

    EmailBody::create(['email_id' => $email->id, 'body_html' => null, 'body_text' => $body]);
    EmailParticipant::factory()->from()->create(['email_id' => $email->id, 'email_address' => $from, 'name' => Str::headline(Str::before($from, '@'))]);

    resolve(RefreshConversationSummary::class)->execute($mailbox->workspace_id, $key);

    return $email;
}

it('finds a conversation by a body word', function (string $theme): void {
    searchBrowserMessage($this->mailbox, 'conv-orchard', 'brian@airbnb.com', 'Orchard update', 'The kumquat harvest is late');
    searchBrowserMessage($this->mailbox, 'conv-pricing', 'olivia@stripe.com', 'Pricing for 40 seats', 'Our floor is 18 dollars');

    $page = loginViaBrowser($this->owner, $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->assertScript('document.documentElement.classList.contains("dark")', $theme === 'inDarkMode')
        ->navigate("/app/{$this->workspace->slug}/email?view=all")
        ->resize(1440, 900)
        ->type('[data-conversation-search] input', 'kumquat')
        ->assertMissing('[data-conversation="conv-pricing"]')
        ->assertVisible('[data-conversation="conv-orchard"]');

    $page->screenshot(filename: "conversations-search-results-{$theme}");

    $page->type('[data-conversation-search] input', 'zebra')
        ->assertSee(__('filament/pages/email-inbox.views.no_match_hint'))
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);

it('turns a typed operator into a chip and back into text when edited', function (string $theme): void {
    searchBrowserMessage($this->mailbox, 'conv-airbnb', 'brian@airbnb.com', 'Pricing', 'Seats');
    searchBrowserMessage($this->mailbox, 'conv-stripe', 'olivia@stripe.com', 'Pricing', 'Seats');

    $page = loginViaBrowser($this->owner, $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->assertScript('document.documentElement.classList.contains("dark")', $theme === 'inDarkMode')
        ->navigate("/app/{$this->workspace->slug}/email?view=all")
        ->resize(1440, 900)
        ->type('[data-conversation-search] input', 'from:airbnb.com pricing')
        ->keys('[data-conversation-search] input', 'Enter')
        ->assertVisible('[data-filter-chip="from"]')
        ->assertValue('[data-conversation-search] input', 'pricing')
        ->assertMissing('[data-conversation="conv-stripe"]');

    $page->screenshot(filename: "conversations-search-chip-{$theme}");

    $page->click('[data-filter-chip="from"] button:first-child')
        ->assertMissing('[data-filter-chip="from"]')
        ->assertValue('[data-conversation-search] input', 'pricing from:airbnb.com')
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);

it('keeps search and chips in the url across reload and scrolls the chips on a phone', function (string $theme): void {
    searchBrowserMessage($this->mailbox, 'conv-airbnb', 'brian@airbnb.com', 'Pricing', 'Seats');

    $page = loginViaBrowser($this->owner, $theme)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->assertScript('document.documentElement.classList.contains("dark")', $theme === 'inDarkMode')
        ->navigate("/app/{$this->workspace->slug}/email?view=all")
        ->resize(390, 844)
        ->type('[data-conversation-search] input', 'from:airbnb.com has:attachment seats')
        ->keys('[data-conversation-search] input', 'Enter')
        ->assertVisible('[data-filter-chip="has_attachment"]')
        ->assertScript('new URL(location.href).searchParams.get("q")', 'seats')
        ->refresh()
        ->assertValue('[data-conversation-search] input', 'seats')
        ->assertVisible('[data-filter-chip="from"]')
        ->assertScript('(() => { const bar = document.querySelector("[data-filter-chips]"); return bar.scrollWidth > bar.clientWidth && getComputedStyle(bar).overflowX === "auto" })()', true)
        ->assertScript('document.querySelector("[data-conversation-search] input").getBoundingClientRect().width > 300', true);

    $page->screenshot(filename: "conversations-search-phone-{$theme}");

    $page->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);
