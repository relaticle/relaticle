<?php

declare(strict_types=1);

use App\Features\EmailConversations;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;
use Relaticle\EmailIntegration\Enums\AutomatedReason;
use Relaticle\EmailIntegration\Enums\ConversationView;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPageTab;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBlocklist;
use Relaticle\EmailIntegration\Models\EmailConversation;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailRead;
use Relaticle\EmailIntegration\Models\EmailShare;
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

it('lists a conversation in Needs reply until any member answers it', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-waiting', 'brian@airbnb.test', ['sent_at' => now()->subHours(3)]);
    conversationsPageMessage($this->ownerMailbox, 'conv-answered', 'kim@notion.test', ['sent_at' => now()->subHours(3)]);
    conversationsPageMessage($this->ownerMailbox, 'conv-answered', 'ana@acme.test', ['sent_at' => now()->subHour()]);
    $inbound = conversationsPageMessage($this->ownerMailbox, 'conv-ben-answered', 'olivia@stripe.test', ['sent_at' => now()->subHours(3)]);
    $reply = conversationsPageMessage($this->teammateMailbox, 'conv-ben-answered', 'ben@acme.test', ['sent_at' => now()->subHour()]);
    $this->company->emails()->attach([$inbound->id, $reply->id]);
    conversationsPageMessage($this->ownerMailbox, 'conv-newsletter', 'news@substack.test', ['automated_reason' => AutomatedReason::List]);

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::NeedsReply->value)
        ->assertSeeHtml('data-conversation="conv-waiting"')
        ->assertDontSeeHtml('data-conversation="conv-answered"')
        ->assertDontSeeHtml('data-conversation="conv-ben-answered"')
        ->assertDontSeeHtml('data-conversation="conv-newsletter"');
});

it('brings a conversation back to Needs reply when the customer writes again', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-thread', 'brian@airbnb.test', ['sent_at' => now()->subHours(3)]);
    conversationsPageMessage($this->ownerMailbox, 'conv-thread', 'ana@acme.test', ['sent_at' => now()->subHours(2)]);
    conversationsPageMessage($this->ownerMailbox, 'conv-thread', 'brian@airbnb.test', ['sent_at' => now()->subHour()]);

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::NeedsReply->value)
        ->assertSeeHtml('data-conversation="conv-thread"');
});

it('leaves a teammate linked conversation out of Needs reply but keeps it in All', function (): void {
    $email = conversationsPageMessage($this->teammateMailbox, 'conv-ben-lead', 'olivia@stripe.test', ['subject' => 'Demo next week']);
    $this->company->emails()->attach($email->id);

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::NeedsReply->value)
        ->assertDontSeeHtml('data-conversation="conv-ben-lead"')
        ->call('setView', ConversationView::All->value)
        ->assertSeeHtml('data-conversation="conv-ben-lead"');
});

it('opens on All when nothing needs a reply and on Needs reply once something does', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-answered', 'kim@notion.test', ['sent_at' => now()->subHours(2)]);
    conversationsPageMessage($this->ownerMailbox, 'conv-answered', 'ana@acme.test', ['sent_at' => now()->subHour()]);

    expect(livewire(EmailInboxPage::class)->instance()->activeView)->toBe(ConversationView::All);

    conversationsPageMessage($this->ownerMailbox, 'conv-waiting', 'brian@airbnb.test');

    livewire(EmailInboxPage::class)
        ->assertSeeHtml('data-conversation="conv-waiting"')
        ->assertDontSeeHtml('data-conversation="conv-answered"');
});

it('keeps an empty Needs reply view the viewer chose in the URL', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-answered', 'kim@notion.test', ['sent_at' => now()->subHours(2)]);
    conversationsPageMessage($this->ownerMailbox, 'conv-answered', 'ana@acme.test', ['sent_at' => now()->subHour()]);

    Livewire::withQueryParams(['view' => 'needs_reply'])
        ->test(EmailInboxPage::class)
        ->assertSee(__('filament/pages/email-inbox.views.empty.needs_reply'))
        ->assertDontSeeHtml('data-conversation="conv-answered"');
});

it('lists what the viewer sent three business days ago with no answer since', function (): void {
    $this->owner->update(['timezone' => 'America/New_York']);
    $this->travelTo(CarbonImmutable::parse('2026-10-30 15:00', 'America/New_York'));

    foreach (['conv-tuesday' => '2026-10-27 14:00', 'conv-wednesday' => '2026-10-28 16:00', 'conv-thursday' => '2026-10-29 09:00'] as $key => $sentAt) {
        conversationsPageMessage($this->ownerMailbox, $key, 'brian@airbnb.test', ['sent_at' => CarbonImmutable::parse($sentAt, 'America/New_York')->subDay()]);
        conversationsPageMessage($this->ownerMailbox, $key, 'ana@acme.test', ['sent_at' => CarbonImmutable::parse($sentAt, 'America/New_York')]);
    }

    $answeredByBen = conversationsPageMessage($this->ownerMailbox, 'conv-ben-followed', 'ana@acme.test', ['sent_at' => now()->subDays(6)]);
    $followUp = conversationsPageMessage($this->teammateMailbox, 'conv-ben-followed', 'ben@acme.test', ['sent_at' => now()->subDays(5)]);
    $this->company->emails()->attach([$answeredByBen->id, $followUp->id]);
    conversationsPageMessage($this->ownerMailbox, 'conv-customer-replied', 'ana@acme.test', ['sent_at' => CarbonImmutable::parse('2026-10-27 10:00', 'America/New_York')]);
    conversationsPageMessage($this->ownerMailbox, 'conv-customer-replied', 'brian@airbnb.test', ['sent_at' => CarbonImmutable::parse('2026-10-28 10:00', 'America/New_York')]);

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::AwaitingReply->value)
        ->assertSeeHtml('data-conversation="conv-tuesday"')
        ->assertDontSeeHtml('data-conversation="conv-wednesday"')
        ->assertDontSeeHtml('data-conversation="conv-ben-followed"')
        ->assertDontSeeHtml('data-conversation="conv-customer-replied"');

    $this->travelTo(CarbonImmutable::parse('2026-10-31 10:00', 'America/New_York'));

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::AwaitingReply->value)
        ->assertSeeHtml('data-conversation="conv-tuesday"')
        ->assertDontSeeHtml('data-conversation="conv-wednesday"');

    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'America/New_York'));

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::AwaitingReply->value)
        ->assertSeeHtml('data-conversation="conv-wednesday"')
        ->assertDontSeeHtml('data-conversation="conv-thursday"');
});

it('hides automated mail from All behind a counted Automated view', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-human', 'brian@airbnb.test');
    conversationsPageMessage($this->ownerMailbox, 'conv-receipt', 'receipts@stripe.test', ['automated_reason' => AutomatedReason::RoleSender]);

    $page = livewire(EmailInboxPage::class)->call('setView', ConversationView::All->value);

    expect(conversationsPageKeys($page->html()))->toBe(['conv-human'])
        ->and($page->instance()->viewCounts['automated'])->toBe(1);

    expect(conversationsPageKeys($page->call('setView', ConversationView::Automated->value)->html()))->toBe(['conv-receipt']);
});

it('lists only the viewer own conversations that no record links in Unlinked', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-own-unlinked', 'brian@airbnb.test');
    $ownLinked = conversationsPageMessage($this->ownerMailbox, 'conv-own-linked', 'kim@notion.test');
    $teammateLinked = conversationsPageMessage($this->teammateMailbox, 'conv-ben-linked', 'olivia@stripe.test');
    $this->company->emails()->attach([$ownLinked->id, $teammateLinked->id]);

    $page = livewire(EmailInboxPage::class)->call('setView', ConversationView::Unlinked->value);

    expect(conversationsPageKeys($page->html()))->toBe(['conv-own-unlinked'])
        ->and($page->instance()->viewCounts['unlinked'])->toBe(1);
});

it('bolds only conversations whose unread mail reached the viewer', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-own', 'brian@airbnb.test');
    $bens = conversationsPageMessage($this->teammateMailbox, 'conv-ben', 'olivia@stripe.test');
    $this->company->emails()->attach($bens->id);
    $read = conversationsPageMessage($this->ownerMailbox, 'conv-read', 'kim@notion.test');
    EmailRead::factory()->create(['email_id' => $read->id, 'user_id' => $this->owner->id, 'read_at' => now()]);
    $shared = conversationsPageMessage($this->teammateMailbox, 'conv-shared', 'lee@linear.test');
    $this->company->emails()->attach($shared->id);
    EmailShare::factory()->create([
        'email_id' => $shared->id,
        'shared_by' => $this->teammate->id,
        'shared_with' => $this->owner->id,
        'tier' => EmailPrivacyTier::FULL->value,
    ]);

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::All->value)
        ->assertSeeHtml('data-conversation="conv-own" data-unread="true"')
        ->assertSeeHtml('data-conversation="conv-ben" data-unread="false"')
        ->assertSeeHtml('data-conversation="conv-read" data-unread="false"')
        ->assertSeeHtml('data-conversation="conv-shared" data-unread="true"');
});

it('renders a teammate row only at the tier the viewer holds', function (EmailPrivacyTier $tier, bool $showsSubject, bool $showsSnippet): void {
    $email = conversationsPageMessage($this->teammateMailbox, 'conv-tier', 'olivia@stripe.test', [
        'subject' => 'Security review findings',
        'snippet' => 'Two critical issues remain open',
        'privacy_tier' => $tier,
    ]);
    $this->company->emails()->attach($email->id);

    $html = livewire(EmailInboxPage::class)->call('setView', ConversationView::All->value)->html();

    expect(str_contains($html, 'Security review findings'))->toBe($showsSubject)
        ->and(str_contains($html, 'Two critical issues remain open'))->toBe($showsSnippet)
        ->and($html)->toContain('Airbnb')
        ->and(str_contains($html, "selectEmail('{$email->id}')"))->toBe($tier === EmailPrivacyTier::FULL);
})->with([
    'metadata' => [EmailPrivacyTier::METADATA_ONLY, false, false],
    'subject' => [EmailPrivacyTier::SUBJECT, true, false],
    'full' => [EmailPrivacyTier::FULL, true, true],
]);

it('offers Request access on a locked teammate row', function (): void {
    $email = conversationsPageMessage($this->teammateMailbox, 'conv-locked', 'olivia@stripe.test', [
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
    ]);
    $this->company->emails()->attach($email->id);

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::All->value)
        ->assertSee(__('filament/pages/email-inbox.subject.hidden'))
        ->assertActionVisible('requestAccess', ['emailId' => $email->id]);
});

it('opens the reader only on a copy the viewer may see', function (): void {
    $own = conversationsPageMessage($this->ownerMailbox, 'conv-own', 'brian@airbnb.test', ['subject' => 'Pricing for 40 seats']);
    $hidden = conversationsPageMessage($this->teammateMailbox, 'conv-hidden', 'olivia@stripe.test');

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::All->value)
        ->call('selectEmail', $hidden->id)
        ->assertSet('selectedEmailId', null)
        ->assertDontSeeHtml('fi-email-reader-panel')
        ->call('selectEmail', $own->id)
        ->assertSet('selectedEmailId', $own->id)
        ->assertSeeHtml('fi-email-reader-panel');
});

it('keeps a conversation out of Needs reply when the viewer own copy is blocked by their mailbox', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-blocked', 'spam@blocked.test');
    $teammateCopy = conversationsPageMessage($this->teammateMailbox, 'conv-blocked', 'brian@airbnb.test');
    $this->company->emails()->attach($teammateCopy->id);
    EmailBlocklist::factory()->email('spam@blocked.test')->create([
        'user_id' => $this->owner->id,
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->ownerMailbox->id,
    ]);

    $page = livewire(EmailInboxPage::class)->call('setView', ConversationView::NeedsReply->value);

    expect(conversationsPageKeys($page->html()))->toBe([])
        ->and($page->instance()->viewCounts['needs_reply'])->toBe(0);

    expect(conversationsPageKeys($page->call('setView', ConversationView::All->value)->html()))->toBe(['conv-blocked']);
});

it('lists and counts only the current workspace when another holds the same conversation key', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-shared-key', 'brian@airbnb.test');
    $before = resolve(ConversationsQuery::class)->counts($this->owner);

    $stranger = User::factory()->withWorkspace()->create();
    conversationsPageMessage(conversationsPageMailbox($stranger, 'sam@other.test'), 'conv-shared-key', 'brian@airbnb.test');
    conversationsPageMessage(conversationsPageMailbox($stranger, 'sam2@other.test'), 'conv-foreign', 'zoe@figma.test');

    $html = livewire(EmailInboxPage::class)->call('setView', ConversationView::All->value)->html();

    expect(conversationsPageKeys($html))->toBe(['conv-shared-key'])
        ->and(resolve(ConversationsQuery::class)->counts($this->owner))->toBe($before);
});

it('keeps the drafts page when conversations are off, even with a view in the URL', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-waiting', 'brian@airbnb.test');
    Feature::for($this->workspace)->deactivate(EmailConversations::class);

    Livewire::withQueryParams(['view' => 'needs_reply'])
        ->test(EmailInboxPage::class)
        ->assertSet('tab', EmailPageTab::DRAFTS)
        ->assertSeeHtml('aria-current="true"')
        ->assertDontSeeHtml('data-view=')
        ->assertDontSeeHtml('data-conversation=')
        ->set('tab', null)
        ->assertDontSeeHtml('data-conversation=');
});

it('pages through conversations that share a timestamp with no repeats and no gaps', function (): void {
    $this->travelTo(now()->startOfMinute());

    foreach (range(1, 49) as $index) {
        conversationsPageMessage($this->ownerMailbox, "conv-{$index}", "c{$index}@client.test", ['sent_at' => now()->subMinutes($index)]);
    }

    foreach (['tie-a', 'tie-b', 'tie-c'] as $key) {
        conversationsPageMessage($this->ownerMailbox, $key, 'tie@client.test', ['sent_at' => now()->subHours(2)]);
    }

    $page = livewire(EmailInboxPage::class)->call('setView', ConversationView::All->value);
    $first = conversationsPageKeys($page->html());
    $second = conversationsPageKeys($page->call('nextConversationsPage')->html());
    $back = conversationsPageKeys($page->call('previousConversationsPage')->html());

    expect($first)->toHaveCount(50)
        ->and($second)->toHaveCount(2)
        ->and(array_intersect($first, $second))->toBe([])
        ->and(array_unique([...$first, ...$second]))->toHaveCount(52)
        ->and($back)->toBe($first);
});

it('renders a page of conversations in the same number of queries for 3 or 20 rows', function (): void {
    $render = function (int $rows): int {
        app()->forgetScopedInstances();
        Facade::clearResolvedInstances();
        Feature::for($this->workspace)->activate(EmailConversations::class);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->workspace);
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $html = livewire(EmailInboxPage::class)->call('setView', ConversationView::All->value)->html();

        expect(conversationsPageKeys($html))->toHaveCount($rows);

        return $queries;
    };

    $seed = function (int $from, int $to): void {
        foreach (range($from, $to) as $index) {
            $email = conversationsPageMessage($index % 2 === 0 ? $this->ownerMailbox : $this->teammateMailbox, "conv-{$index}", "c{$index}@client.test");
            $this->company->emails()->attach($email->id);
        }
    };

    $seed(1, 3);
    $few = $render(3);
    $seed(4, 20);

    expect($render(20))->toBe($few);
});

it('opens the viewer own copy when a teammate copy of the newest message synced a second later', function (): void {
    $own = conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'brian@airbnb.test', [
        'rfc_message_id' => '<pricing-2@airbnb.test>',
        'sent_at' => now()->subSecond(),
    ]);
    $teammates = conversationsPageMessage($this->teammateMailbox, 'conv-pricing', 'brian@airbnb.test', [
        'rfc_message_id' => '<pricing-2@airbnb.test>',
    ]);
    $this->company->emails()->attach([$own->id, $teammates->id]);

    $page = livewire(EmailInboxPage::class)->call('setView', ConversationView::All->value);

    expect($page->html())->toContain("selectEmail('{$own->id}')")
        ->not->toContain($teammates->id);

    $page->call('selectEmail', $own->id)
        ->assertSet('selectedEmailId', $own->id)
        ->assertSeeHtml('fi-email-reader-panel');
});

it('grants the most open share when copies of one message are shared at different levels', function (): void {
    $metadataCopy = conversationsPageMessage($this->teammateMailbox, 'conv-shared', 'lee@linear.test', [
        'rfc_message_id' => '<shared-1@linear.test>',
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
    ]);
    $fullCopy = conversationsPageMessage(conversationsPageMailbox($this->teammate, 'ben.sales@acme.test'), 'conv-shared', 'lee@linear.test', [
        'rfc_message_id' => '<shared-1@linear.test>',
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
    ]);
    $unsharedCopy = conversationsPageMessage(conversationsPageMailbox($this->teammate, 'ben.ops@acme.test'), 'conv-shared', 'lee@linear.test', [
        'rfc_message_id' => '<shared-1@linear.test>',
        'privacy_tier' => EmailPrivacyTier::SUBJECT,
        'snippet' => 'Signed order form attached',
    ]);

    foreach ([[$metadataCopy, EmailPrivacyTier::METADATA_ONLY], [$fullCopy, EmailPrivacyTier::FULL]] as [$copy, $tier]) {
        EmailShare::factory()->tier($tier)->create([
            'email_id' => $copy->id,
            'shared_by' => $this->teammate->id,
            'shared_with' => $this->owner->id,
        ]);
    }

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::All->value)
        ->assertSee('Signed order form attached')
        ->assertSeeHtml("selectEmail('{$unsharedCopy->id}')")
        ->call('selectEmail', $unsharedCopy->id)
        ->assertSet('selectedEmailId', $unsharedCopy->id);
});

it('keeps the default view while paging even when its count changes', function (): void {
    $this->travelTo(now()->startOfMinute());

    foreach (range(1, 51) as $index) {
        conversationsPageMessage($this->ownerMailbox, "conv-{$index}", "c{$index}@client.test", ['sent_at' => now()->subMinutes($index)]);
    }

    $page = livewire(EmailInboxPage::class)
        ->call('nextConversationsPage')
        ->assertSet('conversationView', ConversationView::NeedsReply);

    expect(conversationsPageKeys($page->html()))->toBe(['conv-51']);

    EmailConversation::query()->update(['last_member_outbound_at' => now()]);

    expect(conversationsPageKeys($page->call('$refresh')->html()))->toBe([]);
});

it('stops each view count at the cap and shows it as 99+', function (): void {
    foreach (range(1, ConversationView::COUNT_CAP + 1) as $index) {
        conversationsPageMessage($this->ownerMailbox, "conv-{$index}", "c{$index}@client.test");
    }

    $page = livewire(EmailInboxPage::class);

    expect($page->instance()->viewCounts['needs_reply'])->toBe(ConversationView::COUNT_CAP);
    $page->assertSeeHtml('>99+</span>');
});
