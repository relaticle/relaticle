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
