<?php

declare(strict_types=1);

use App\Features\EmailConversations;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;
use Relaticle\EmailIntegration\Enums\ConversationView;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;
use Relaticle\EmailIntegration\Services\PreferredEmailCopyService;

use function Pest\Laravel\actingAs;

mutates(EmailInboxPage::class, ConversationsQuery::class, ConversationView::class, PreferredEmailCopyService::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->teammate = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($this->teammate, ['role' => 'member']);

    $this->ownerMailbox = conversationsPageMailbox($this->owner, 'ana@acme.test');
    $this->teammateMailbox = conversationsPageMailbox($this->teammate, 'ben@acme.test');
    $this->company = Company::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Airbnb']);

    Feature::for($this->workspace)->activate(EmailConversations::class);
    actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->workspace);
});

function conversationsPageMailbox(User $user, string $address): ConnectedAccount
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
function conversationsPageMessage(ConnectedAccount $mailbox, string $key, string $from, array $attributes = []): Email
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
function conversationsPageKeys(string $html): array
{
    preg_match_all('/data-conversation="([^"]+)"/', $html, $matches);

    return $matches[1];
}

it('shows a conversation held by two mailboxes once, through the viewer own copy', function (): void {
    $ownerCopy = conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'brian@airbnb.test', [
        'rfc_message_id' => '<pricing-1@airbnb.test>',
        'subject' => 'Pricing for 40 seats',
    ]);
    $teammateCopy = conversationsPageMessage($this->teammateMailbox, 'conv-pricing', 'brian@airbnb.test', [
        'rfc_message_id' => '<pricing-1@airbnb.test>',
        'subject' => 'Pricing for 40 seats',
    ]);
    $this->company->emails()->attach([$ownerCopy->id, $teammateCopy->id]);

    $html = livewire(EmailInboxPage::class)->call('setView', ConversationView::All->value)->html();

    expect(conversationsPageKeys($html))->toBe(['conv-pricing'])
        ->and($html)->toContain("selectEmail('{$ownerCopy->id}')")
        ->and($html)->toContain('data-message-count="1"')
        ->and($html)->not->toContain($teammateCopy->id);
});

it('never shows a teammate message that is linked to no record, alone or inside a shared conversation', function (): void {
    conversationsPageMessage($this->teammateMailbox, 'conv-ben-only', 'olivia@stripe.test', [
        'subject' => 'Offer letter for Ben',
    ]);
    conversationsPageMessage($this->ownerMailbox, 'conv-mixed', 'brian@airbnb.test', [
        'subject' => 'Renewal terms',
        'sent_at' => now()->subHour(),
    ]);
    conversationsPageMessage($this->teammateMailbox, 'conv-mixed', 'brian@airbnb.test', [
        'subject' => 'Re: Renewal terms',
        'snippet' => 'Our floor is 18 dollars a seat',
    ]);

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::All->value)
        ->assertDontSee('Offer letter for Ben')
        ->assertDontSeeHtml('data-conversation="conv-ben-only"')
        ->assertSeeHtml('data-conversation="conv-mixed"')
        ->assertSee('Renewal terms')
        ->assertDontSee('Our floor is 18 dollars a seat')
        ->assertSeeHtml('data-message-count="1"');
});
