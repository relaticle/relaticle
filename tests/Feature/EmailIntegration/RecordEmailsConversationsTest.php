<?php

declare(strict_types=1);

use App\Enums\CrmEntity;
use App\Enums\CustomFields\CompanyField;
use App\Features\EmailConversations;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\CompanyResource\RelationManagers\EmailsRelationManager;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Filament\Resources\OpportunityResource\RelationManagers\EmailsRelationManager as OpportunityEmailsRelationManager;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;
use Relaticle\EmailIntegration\Actions\UnlinkEmailFromRecord;
use Relaticle\EmailIntegration\Data\ConversationFilters;
use Relaticle\EmailIntegration\Data\ConversationRow;
use Relaticle\EmailIntegration\Enums\AutomatedReason;
use Relaticle\EmailIntegration\Enums\ConversationView;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Enums\MailboxHealth;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Filament\RelationManagers\BaseEmailsRelationManager;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailRead;
use Relaticle\EmailIntegration\Models\WorkspaceEmailBlocklist;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;

use function Pest\Laravel\actingAs;

mutates(BaseEmailsRelationManager::class, ConversationsQuery::class, Email::class, Opportunity::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->teammate = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($this->teammate, ['role' => 'member']);

    $this->ownerMailbox = conversationsRecordMailbox($this->owner, 'ana@acme.test');
    $this->teammateMailbox = conversationsRecordMailbox($this->teammate, 'ben@acme.test');
    $this->company = Company::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Airbnb']);

    config(['relaticle.features.email_conversations' => [$this->workspace->id]]);
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

it('picks up a conversation linked to the company when the tab is refreshed', function (): void {
    $tab = livewire(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class]);

    $email = conversationsRecordMessage($this->ownerMailbox, 'conv-renewal', 'brian@airbnb.test');
    $this->company->emails()->attach($email->id);
    $tab->call('refreshConversations');

    expect(conversationsRecordKeys($tab->html()))->toBe(['conv-renewal']);
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
        ->assertSeeHtml('fi-email-reader-drawer');
});

it('opens the reader from the email in the URL only while conversations are on', function (): void {
    $email = conversationsRecordMessage($this->ownerMailbox, 'conv-human', 'brian@airbnb.test');
    $this->company->emails()->attach($email->id);

    Livewire::withQueryParams(['email' => $email->id])
        ->test(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])
        ->assertSet('selectedEmailId', $email->id)
        ->assertSeeHtml('fi-email-reader-drawer');

    config(['relaticle.features.email_conversations' => []]);
    Feature::purge(EmailConversations::class);

    Livewire::withQueryParams(['email' => $email->id])
        ->test(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])
        ->assertSet('selectedEmailId', null)
        ->assertDontSeeHtml('fi-email-reader-panel');
});

it('leaves out the viewer own conversation linked to another company', function (): void {
    $linked = conversationsRecordMessage($this->ownerMailbox, 'conv-linked', 'brian@airbnb.test');
    $this->company->emails()->attach($linked->id);
    $other = Company::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Stripe']);
    $other->emails()->attach(conversationsRecordMessage($this->ownerMailbox, 'conv-stripe', 'olivia@stripe.test')->id);

    $html = livewire(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])->html();

    expect(conversationsRecordKeys($html))->toBe(['conv-linked']);
});

it('lists no conversations on a company whose mailbox is hidden', function (): void {
    $domains = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->id)
        ->where('entity_type', 'company')
        ->where('code', CompanyField::DOMAINS->value)
        ->firstOrFail();
    $this->company->saveCustomFieldValue($domains, ['https://airbnb.test'], $this->workspace);
    WorkspaceEmailBlocklist::factory()->protected()->domain('airbnb.test')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->owner->id,
    ]);
    $email = conversationsRecordMessage($this->ownerMailbox, 'conv-human', 'brian@airbnb.test');
    $this->company->emails()->attach($email->id);

    $html = livewire(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])->html();

    expect(conversationsRecordKeys($html))->toBe([]);
});

it('opens the queued send on the record and drops an id it cannot open', function (): void {
    $queued = Email::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->ownerMailbox->id,
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::QUEUED,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'is_internal' => false,
        'sent_at' => null,
    ]);
    $this->company->emails()->attach($queued->id);

    livewire(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])
        ->dispatch('composer:sent', emailId: $queued->id)
        ->assertSet('selectedEmailId', $queued->id)
        ->assertSeeHtml('fi-email-reader-drawer')
        ->dispatch('composer:sent', emailId: (string) Str::ulid())
        ->assertSet('selectedEmailId', null)
        ->assertDontSeeHtml('fi-email-reader-drawer');
});

it('opens only this record conversations from the email in the URL', function (): void {
    $elsewhere = conversationsRecordMessage($this->ownerMailbox, 'conv-stripe', 'olivia@stripe.test');

    Livewire::withQueryParams(['email' => $elsewhere->id])
        ->test(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])
        ->assertSet('selectedEmailId', null)
        ->assertDontSeeHtml('fi-email-reader-drawer');
});

it('keeps the list visible beside the open reader', function (): void {
    $email = conversationsRecordMessage($this->ownerMailbox, 'conv-open', 'brian@airbnb.test', ['subject' => 'Kickoff agenda']);
    $other = conversationsRecordMessage($this->ownerMailbox, 'conv-other', 'kim@airbnb.test', ['subject' => 'Invoice question']);
    $this->company->emails()->attach([$email->id, $other->id]);

    livewire(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])
        ->call('selectEmail', $email->id)
        ->assertSeeHtml('fi-email-reader-drawer')
        ->assertDontSeeHtml('fi-email-reader-backdrop')
        ->assertSeeHtml('data-conversation="conv-other"')
        ->assertDontSeeHtml('flex flex-col invisible');
});

it('opens a locked teammate conversation at the gate without its subject', function (): void {
    $email = conversationsRecordMessage($this->teammateMailbox, 'conv-gate', 'olivia@airbnb.test', [
        'subject' => 'Contract redlines',
        'snippet' => 'Clause 7 is a blocker',
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
    ]);
    $this->company->emails()->attach($email->id);

    livewire(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])
        ->call('selectEmail', $email->id)
        ->assertSeeHtml('fi-email-reader-drawer')
        ->assertSee(__('filament/pages/email-inbox.subject.hidden'))
        ->assertDontSee('Contract redlines')
        ->assertDontSee('Clause 7 is a blocker')
        ->assertActionVisible('requestAccess', ['emailId' => $email->id]);

    expect(EmailRead::query()->where('email_id', $email->id)->exists())->toBeFalse();
});

it('deep links to a locked email land on the gate, and to hidden mail on the not available state', function (): void {
    $locked = conversationsRecordMessage($this->teammateMailbox, 'conv-locked', 'olivia@airbnb.test', [
        'subject' => 'Contract redlines',
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
    ]);
    $this->company->emails()->attach($locked->id);
    $hidden = conversationsRecordMessage($this->teammateMailbox, 'conv-hidden', 'olivia@airbnb.test', ['subject' => 'Ben side project']);

    Livewire::withQueryParams(['email' => $locked->id])
        ->test(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])
        ->assertSeeHtml('fi-email-reader-drawer')
        ->assertDontSee('Contract redlines');

    Livewire::withQueryParams(['email' => $hidden->id])
        ->test(EmailInboxPage::class)
        ->assertSee(__('filament/pages/email-inbox.drawer.not_available'))
        ->assertDontSee('Ben side project');
});

it('reads the same not available line for missing, private, unlinked teammate and other workspace mail', function (): void {
    $private = conversationsRecordMessage($this->teammateMailbox, 'conv-private', 'olivia@airbnb.test', [
        'subject' => 'Private compensation thread',
        'privacy_tier' => EmailPrivacyTier::PRIVATE,
    ]);
    $this->company->emails()->attach($private->id);
    $unlinked = conversationsRecordMessage($this->teammateMailbox, 'conv-unlinked', 'olivia@airbnb.test', ['subject' => 'Ben side project']);
    $otherWorkspace = Workspace::factory()->create();
    $otherWorkspace->users()->attach($this->owner, ['role' => 'member']);
    $otherMailbox = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'user_id' => $this->owner->id,
        'email_address' => 'ana@other.test',
        'status' => 'active',
    ]));
    $elsewhere = conversationsRecordMessage($otherMailbox, 'conv-elsewhere', 'kim@other.test', ['subject' => 'Other workspace deal']);
    $this->company->emails()->attach($elsewhere->id);

    foreach ([(string) Str::ulid(), $private->id, $unlinked->id, $elsewhere->id] as $id) {
        Livewire::withQueryParams(['email' => $id])
            ->test(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])
            ->assertSet('selectedEmailId', $id)
            ->assertSee(__('filament/pages/email-inbox.drawer.not_available'))
            ->assertDontSee('Private compensation thread')
            ->assertDontSee('Ben side project')
            ->assertDontSee('Other workspace deal')
            ->call('deselectEmail')
            ->assertSet('selectedEmailId', null)
            ->assertDontSee(__('filament/pages/email-inbox.drawer.not_available'));
    }
});

it('saves the reply draft when the client clears the email open on the record', function (): void {
    $email = conversationsRecordMessage($this->ownerMailbox, 'conv-back', 'brian@airbnb.test');
    $this->company->emails()->attach($email->id);

    livewire(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])
        ->call('selectEmail', $email->id)
        ->set('selectedEmailId', null)
        ->assertDispatched('composer:dismiss-inline')
        ->assertDontSeeHtml('fi-email-reader-drawer');
});

it('shows the not available line for mail no conversation holds yet', function (): void {
    $unkeyed = conversationsRecordMessage($this->ownerMailbox, 'conv-unkeyed', 'brian@airbnb.test', ['subject' => 'Unkeyed renewal']);
    $unkeyed->forceFill(['conversation_key' => null])->saveQuietly();
    $this->company->emails()->attach($unkeyed->id);

    Livewire::withQueryParams(['email' => $unkeyed->id])
        ->test(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])
        ->assertNoRedirect()
        ->assertSet('selectedEmailId', $unkeyed->id)
        ->assertSee(__('filament/pages/email-inbox.drawer.not_available'))
        ->assertDontSee('Unkeyed renewal');
});

it('keeps the conversations, the automated count and the open email of a trashed company', function (): void {
    $human = conversationsRecordMessage($this->ownerMailbox, 'conv-human', 'brian@airbnb.test');
    $invoice = conversationsRecordMessage($this->ownerMailbox, 'conv-invoice', 'billing@airbnb.test', ['automated_reason' => AutomatedReason::RoleSender]);
    $this->company->emails()->attach([$human->id, $invoice->id]);
    $this->company->delete();

    $tab = Livewire::withQueryParams(['email' => $human->id])
        ->test(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])
        ->assertSet('selectedEmailId', $human->id)
        ->assertSeeHtml('fi-email-reader-drawer')
        ->assertDontSee(__('filament/pages/email-inbox.drawer.not_available'))
        ->assertSee(trans_choice('filament/pages/record-emails.automated_toggle', 1, ['count' => 1]));

    expect(conversationsRecordKeys($tab->html()))->toBe(['conv-human']);
});

function opportunityTabKeys(Opportunity $opportunity): array
{
    $keys = conversationsRecordKeys(livewire(OpportunityEmailsRelationManager::class, ['ownerRecord' => $opportunity, 'pageClass' => ViewOpportunity::class])->html());
    sort($keys);

    return $keys;
}

function setOpportunityCloseDate(Opportunity $opportunity, string $date): void
{
    $field = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $opportunity->workspace_id)
        ->where('entity_type', 'opportunity')
        ->where('code', 'close_date')
        ->firstOrFail();

    $opportunity->saveCustomFieldValue($field, $date, $opportunity->workspace);
}

it('leaves company mail older than 30 days before an opportunity was created off its tab', function (): void {
    $this->travelTo(now()->setDate(2026, 6, 1));
    $old = conversationsRecordMessage($this->ownerMailbox, 'conv-old', 'brian@airbnb.test', ['sent_at' => now()->subDays(45)]);
    $recent = conversationsRecordMessage($this->ownerMailbox, 'conv-recent', 'brian@airbnb.test', ['sent_at' => now()->subDays(10)]);
    $this->company->emails()->attach([$old->id, $recent->id], ['link_source' => 'auto']);

    $opportunity = Opportunity::factory()->for($this->workspace)->create(['company_id' => $this->company->id]);

    expect(opportunityTabKeys($opportunity))->toBe(['conv-recent']);
});

it('shows the point of contact mail inside the window and nothing for an opportunity with neither', function (): void {
    $this->travelTo(now()->setDate(2026, 6, 1));
    $person = People::factory()->for($this->workspace)->create();
    $email = conversationsRecordMessage($this->ownerMailbox, 'conv-contact', 'kim@notion.test', ['sent_at' => now()->subDays(5)]);
    $person->emails()->attach($email->id, ['link_source' => 'auto']);

    $withContact = Opportunity::factory()->for($this->workspace)->create(['contact_id' => $person->id]);
    $alone = Opportunity::factory()->for($this->workspace)->create();

    expect(opportunityTabKeys($withContact))->toBe(['conv-contact'])
        ->and(opportunityTabKeys($alone))->toBe([]);
});

it('keeps a pinned conversation on the opportunity whatever its date', function (): void {
    $this->travelTo(now()->setDate(2026, 6, 1));
    $opportunity = Opportunity::factory()->for($this->workspace)->create(['company_id' => $this->company->id]);
    $pinned = conversationsRecordMessage($this->ownerMailbox, 'conv-pinned', 'brian@airbnb.test', ['sent_at' => now()->subDays(400)]);
    $opportunity->emails()->attach($pinned->id, ['link_source' => 'manual']);

    expect(opportunityTabKeys($opportunity))->toBe(['conv-pinned']);
});

it('ignores an automatic opportunity link outside the window', function (): void {
    $this->travelTo(now()->setDate(2026, 6, 1));
    $opportunity = Opportunity::factory()->for($this->workspace)->create();
    $synced = conversationsRecordMessage($this->ownerMailbox, 'conv-synced', 'brian@airbnb.test', ['sent_at' => now()->subDays(400)]);
    $opportunity->emails()->attach($synced->id, ['link_source' => 'auto']);

    expect(opportunityTabKeys($opportunity))->toBe([]);
});

it('ends the window 30 days after a past close date', function (): void {
    $this->travelTo(now()->setDate(2026, 6, 1));
    $opportunity = Opportunity::factory()->for($this->workspace)->create(['company_id' => $this->company->id, 'created_at' => now()->subDays(120)]);
    setOpportunityCloseDate($opportunity, now()->subDays(60)->toDateString());
    $after = conversationsRecordMessage($this->ownerMailbox, 'conv-after', 'brian@airbnb.test', ['sent_at' => now()->subDays(20)]);
    $before = conversationsRecordMessage($this->ownerMailbox, 'conv-before', 'brian@airbnb.test', ['sent_at' => now()->subDays(40)]);
    $this->company->emails()->attach([$after->id, $before->id], ['link_source' => 'auto']);

    expect(opportunityTabKeys($opportunity))->toBe(['conv-before']);

    setOpportunityCloseDate($opportunity, now()->addDays(10)->toDateString());

    expect(opportunityTabKeys($opportunity))->toBe(['conv-after', 'conv-before']);
});

it('reads the close date of an opportunity loaded with its custom field values', function (): void {
    $this->travelTo(now()->setDate(2026, 6, 1));
    $opportunity = Opportunity::factory()->for($this->workspace)->create(['company_id' => $this->company->id]);
    setOpportunityCloseDate($opportunity, now()->addDays(10)->toDateString());
    $amount = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $opportunity->workspace_id)
        ->where('entity_type', 'opportunity')
        ->where('code', 'amount')
        ->firstOrFail();
    $opportunity->saveCustomFieldValue($amount, 1200, $opportunity->workspace);
    $email = conversationsRecordMessage($this->ownerMailbox, 'conv-loaded', 'brian@airbnb.test', ['sent_at' => now()->subDays(3)]);
    $this->company->emails()->attach($email->id, ['link_source' => 'auto']);

    $loaded = Opportunity::query()->with('customFieldValues')->findOrFail($opportunity->id);

    expect(opportunityTabKeys($loaded))->toBe(['conv-loaded']);
});

it('drops a derived conversation after a user removes it from the opportunity', function (): void {
    $this->travelTo(now()->setDate(2026, 6, 1));
    $opportunity = Opportunity::factory()->for($this->workspace)->create(['company_id' => $this->company->id]);
    $first = conversationsRecordMessage($this->ownerMailbox, 'conv-derived', 'brian@airbnb.test', ['sent_at' => now()->subDays(3)]);
    $this->company->emails()->attach($first->id, ['link_source' => 'auto']);

    resolve(UnlinkEmailFromRecord::class)->execute($this->owner, $first, $opportunity);
    $reply = conversationsRecordMessage($this->ownerMailbox, 'conv-derived', 'brian@airbnb.test', ['sent_at' => now()->subDay()]);
    $this->company->emails()->attach($reply->id, ['link_source' => 'auto']);

    expect(opportunityTabKeys($opportunity))->toBe([])
        ->and($this->company->emails()->whereKey($first->id)->exists())->toBeTrue();
});

it('matches stored links that no holder of that copy removed when the filter names several records', function (): void {
    $other = Company::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Airtable']);
    $kept = conversationsRecordMessage($this->ownerMailbox, 'conv-kept', 'brian@airbnb.test');
    $removed = conversationsRecordMessage($this->ownerMailbox, 'conv-removed', 'brian@airbnb.test');
    $this->company->emails()->attach($kept->id);
    $other->emails()->attach($removed->id);
    $teammateCopy = conversationsRecordMessage($this->teammateMailbox, 'conv-removed', 'brian@airbnb.test', ['rfc_message_id' => $removed->rfc_message_id]);
    $other->emails()->attach($teammateCopy->id);
    resolve(UnlinkEmailFromRecord::class)->execute($this->teammate, $teammateCopy, $other);

    $rows = resolve(ConversationsQuery::class)->paginate($this->owner, ConversationView::All, new ConversationFilters(recordType: CrmEntity::Company, recordName: 'Air'))->rows;

    expect(array_map(fn (ConversationRow $row): string => $row->conversationKey, $rows))->toEqualCanonicalizing(['conv-kept', 'conv-removed']);

    resolve(UnlinkEmailFromRecord::class)->execute($this->owner, $removed, $other);
    $rows = resolve(ConversationsQuery::class)->paginate($this->owner, ConversationView::All, new ConversationFilters(recordType: CrmEntity::Company, recordName: 'Air'))->rows;

    expect(array_map(fn (ConversationRow $row): string => $row->conversationKey, $rows))->toBe(['conv-kept']);
});
