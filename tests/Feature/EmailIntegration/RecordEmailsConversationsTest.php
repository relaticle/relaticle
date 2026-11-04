<?php

declare(strict_types=1);

use App\Features\EmailConversations;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\CompanyResource\RelationManagers\EmailsRelationManager;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;
use Relaticle\EmailIntegration\Enums\AutomatedReason;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Enums\MailboxHealth;
use Relaticle\EmailIntegration\Filament\RelationManagers\BaseEmailsRelationManager;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;

use function Pest\Laravel\actingAs;

mutates(BaseEmailsRelationManager::class, ConversationsQuery::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->teammate = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($this->teammate, ['role' => 'member']);

    $this->ownerMailbox = conversationsRecordMailbox($this->owner, 'ana@acme.test');
    $this->teammateMailbox = conversationsRecordMailbox($this->teammate, 'ben@acme.test');
    $this->company = Company::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Airbnb']);

    Feature::for($this->workspace)->activate(EmailConversations::class);
    actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->workspace);
});

function conversationsRecordMailbox(User $user, string $address): ConnectedAccount
{
    return ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $user->current_workspace_id,
        'user_id' => $user->id,
        'email_address' => $address,
        'status' => 'active',
    ]));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function conversationsRecordMessage(ConnectedAccount $mailbox, string $key, string $from, array $attributes = []): Email
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

/**
 * @return list<string>
 */
function conversationsRecordKeys(string $html): array
{
    preg_match_all('/data-conversation="([^"]+)"/', $html, $matches);

    return $matches[1];
}

it('lists each conversation linked to the company once', function (): void {
    $first = conversationsRecordMessage($this->ownerMailbox, 'conv-renewal', 'brian@airbnb.test', ['rfc_message_id' => '<r1@airbnb.test>']);
    $copy = conversationsRecordMessage($this->teammateMailbox, 'conv-renewal', 'brian@airbnb.test', ['rfc_message_id' => '<r1@airbnb.test>']);
    $reply = conversationsRecordMessage($this->ownerMailbox, 'conv-renewal', 'ana@acme.test');
    $this->company->emails()->attach([$first->id, $copy->id, $reply->id]);

    $html = livewire(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])->html();

    expect(conversationsRecordKeys($html))->toBe(['conv-renewal'])
        ->and($html)->toContain('data-message-count="2"');
});

it('shows a teammate conversation on the company only while it is linked there', function (): void {
    $linked = conversationsRecordMessage($this->teammateMailbox, 'conv-linked', 'olivia@airbnb.test', [
        'subject' => 'Contract redlines',
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
    ]);
    $this->company->emails()->attach($linked->id);
    conversationsRecordMessage($this->teammateMailbox, 'conv-elsewhere', 'olivia@airbnb.test', ['subject' => 'Lunch on Friday']);

    livewire(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])
        ->assertSeeHtml('data-conversation="conv-linked"')
        ->assertDontSee('Contract redlines')
        ->assertDontSeeHtml('data-conversation="conv-elsewhere"')
        ->assertDontSee('Lunch on Friday');
});

it('keeps automated mail behind a counted toggle on the record', function (): void {
    $human = conversationsRecordMessage($this->ownerMailbox, 'conv-human', 'brian@airbnb.test');
    $invoice = conversationsRecordMessage($this->ownerMailbox, 'conv-invoice', 'billing@airbnb.test', ['automated_reason' => AutomatedReason::RoleSender]);
    $this->company->emails()->attach([$human->id, $invoice->id]);

    $tab = livewire(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class]);

    expect(conversationsRecordKeys($tab->html()))->toBe(['conv-human']);
    $tab->assertSee(trans_choice('filament/pages/record-emails.automated_toggle', 1, ['count' => 1]));
    expect(conversationsRecordKeys($tab->set('showAutomated', true)->html()))->toBe(['conv-invoice']);
});

it('keeps the mailbox health banner above the conversations on the record', function (): void {
    $this->ownerMailbox->forceFill(['health' => MailboxHealth::ReauthRequired, 'health_changed_at' => now()])->saveQuietly();
    $email = conversationsRecordMessage($this->ownerMailbox, 'conv-human', 'brian@airbnb.test');
    $this->company->emails()->attach($email->id);

    livewire(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])
        ->assertSeeHtml('data-mailbox-health="reauth_required"')
        ->assertSeeHtml('data-conversation="conv-human"');
});

it('opens a conversation from the record tab through the viewer readable copy', function (): void {
    $email = conversationsRecordMessage($this->ownerMailbox, 'conv-human', 'brian@airbnb.test');
    $this->company->emails()->attach($email->id);
    $hidden = conversationsRecordMessage($this->teammateMailbox, 'conv-hidden', 'olivia@stripe.test');

    livewire(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])
        ->call('selectEmail', $hidden->id)
        ->assertSet('selectedEmailId', null)
        ->call('selectEmail', $email->id)
        ->assertSet('selectedEmailId', $email->id)
        ->assertSeeHtml('fi-email-reader-panel');
});

it('opens the reader from the email in the URL only while conversations are on', function (): void {
    $email = conversationsRecordMessage($this->ownerMailbox, 'conv-human', 'brian@airbnb.test');
    $this->company->emails()->attach($email->id);

    Livewire::withQueryParams(['email' => $email->id])
        ->test(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])
        ->assertSet('selectedEmailId', $email->id)
        ->assertSeeHtml('fi-email-reader-panel');

    Feature::for($this->workspace)->deactivate(EmailConversations::class);

    Livewire::withQueryParams(['email' => $email->id])
        ->test(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])
        ->assertSet('selectedEmailId', null)
        ->assertDontSeeHtml('fi-email-reader-panel');
});
