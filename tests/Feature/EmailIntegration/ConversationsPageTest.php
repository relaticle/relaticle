<?php

declare(strict_types=1);

use App\Actions\People\AddEmailAddressToPerson;
use App\Features\EmailConversations;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\CustomFieldFilterSchema;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Relaticle\Chat\Jobs\SummarizeEmailConversationJob;
use Relaticle\EmailIntegration\Actions\MarkConversationsDone;
use Relaticle\EmailIntegration\Actions\MarkConversationsRead;
use Relaticle\EmailIntegration\Actions\MarkConversationsUnread;
use Relaticle\EmailIntegration\Actions\MarkEmailAsReadAction;
use Relaticle\EmailIntegration\Actions\PrepareConversationTriage;
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;
use Relaticle\EmailIntegration\Actions\ReopenConversations;
use Relaticle\EmailIntegration\Actions\UpdateEmailSharingAction;
use Relaticle\EmailIntegration\Data\ConversationFilters;
use Relaticle\EmailIntegration\Data\TriageOutcome;
use Relaticle\EmailIntegration\Enums\AutomatedReason;
use Relaticle\EmailIntegration\Enums\ConversationView;
use Relaticle\EmailIntegration\Enums\EmailCategory;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFilter;
use Relaticle\EmailIntegration\Enums\EmailPageTab;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Enums\TriageSkipReason;
use Relaticle\EmailIntegration\Events\ConversationChanged;
use Relaticle\EmailIntegration\Filament\Concerns\HasConversationFilters;
use Relaticle\EmailIntegration\Filament\Concerns\HasConversationReader;
use Relaticle\EmailIntegration\Filament\Concerns\HasUnlinkedSenders;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Listeners\ForgetConversationCountsListener;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBlocklist;
use Relaticle\EmailIntegration\Models\EmailBody;
use Relaticle\EmailIntegration\Models\EmailConversation;
use Relaticle\EmailIntegration\Models\EmailConversationState;
use Relaticle\EmailIntegration\Models\EmailLabel;
use Relaticle\EmailIntegration\Models\EmailLinkSuppression;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailRead;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;
use Relaticle\EmailIntegration\Queries\UnlinkedSendersQuery;
use Relaticle\EmailIntegration\Services\EmailSearchService;
use Relaticle\EmailIntegration\Services\PreferredEmailCopyService;

use function Pest\Laravel\actingAs;

mutates(ForgetConversationCountsListener::class, EmailInboxPage::class, HasConversationReader::class, ConversationsQuery::class, MarkEmailAsReadAction::class, ConversationView::class, PreferredEmailCopyService::class, ConversationFilters::class, EmailFilter::class, EmailSearchService::class, HasConversationFilters::class, MarkConversationsDone::class, ReopenConversations::class, MarkConversationsRead::class, MarkConversationsUnread::class, PrepareConversationTriage::class, TriageOutcome::class, TriageSkipReason::class, UnlinkedSendersQuery::class, HasUnlinkedSenders::class, AddEmailAddressToPerson::class);

beforeEach(function (): void {
    Queue::fake([SummarizeEmailConversationJob::class]);
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->teammate = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($this->teammate, ['role' => 'member']);

    $this->ownerMailbox = conversationsPageMailbox($this->owner, 'ana@acme.test');
    $this->teammateMailbox = conversationsPageMailbox($this->teammate, 'ben@acme.test');
    $this->company = Company::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Airbnb']);

    config(['relaticle.features.email_conversations' => [$this->workspace->id]]);
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
 * @param  list<string>  $keys
 * @return array<string, string|null>
 */
function conversationsPageSeen(array $keys): array
{
    $html = livewire(EmailInboxPage::class)->call('setView', ConversationView::All->value)->html();

    return collect($keys)->mapWithKeys(function (string $key) use ($html): array {
        preg_match('/data-conversation="'.preg_quote($key, '/').'"[^>]*?data-last-inbound="([^"]*)"/', $html, $rendered);

        return [$key => ($rendered[1] ?? '') === '' ? null : $rendered[1]];
    })->all();
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

    $this->travel(ConversationsQuery::COUNTS_CACHE_SECONDS + 1)->seconds();
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

    $page = livewire(EmailInboxPage::class)->call('setView', ConversationView::Unlinked->value)->call('toggleSender', 'brian@airbnb.test');

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
        ->and($html)->toContain("selectEmail('{$email->id}')");
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
        ->assertDontSeeHtml('fi-email-reader-drawer')
        ->call('selectEmail', $own->id)
        ->assertSet('selectedEmailId', $own->id)
        ->assertSeeHtml('fi-email-reader-drawer');
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
    config(['relaticle.features.email_conversations' => []]);
    Feature::purge(EmailConversations::class);

    Livewire::withQueryParams(['view' => 'needs_reply'])
        ->test(EmailInboxPage::class)
        ->assertSet('tab', EmailPageTab::DRAFTS->value)
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
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->workspace);
        Cache::forget(ConversationsQuery::countsCacheKey($this->owner));
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

it('opens a conversation in the same number of queries for 3 or 20 messages', function (): void {
    $open = function (string $key, int $messages): int {
        $newest = null;
        foreach (range(1, $messages) as $index) {
            $newest = conversationsPageMessage(($messages - $index) % 2 === 0 ? $this->ownerMailbox : $this->teammateMailbox, $key, "c{$index}@client.test", ['sent_at' => now()->subMinutes(100 - $index)]);
            EmailBody::factory()->create(['email_id' => $newest->id, 'body_html' => null, 'body_text' => "Body {$index}"]);
            $this->company->emails()->attach($newest->id);
        }

        $page = livewire(EmailInboxPage::class);
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $page->call('selectEmail', $newest->id)->assertSee("Body {$messages}");

        return $queries;
    };

    $open('conv-warm-up', 3);

    expect($open('conv-long', 20))->toBe($open('conv-short', 3));
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
        ->assertSeeHtml('fi-email-reader-drawer');
});

it('opens only the directly shared copy when copies of one message have different shares', function (): void {
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
        ->assertDontSee('Signed order form attached')
        ->assertSeeHtml("selectEmail('{$fullCopy->id}')")
        ->call('selectEmail', $fullCopy->id)
        ->assertSet('selectedEmailId', $fullCopy->id)
        ->call('selectEmail', $unsharedCopy->id)
        ->assertSet('selectedEmailId', null);
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

it('shows every visible message of the conversation oldest first and loads bodies on expand', function (): void {
    $first = conversationsPageMessage($this->ownerMailbox, 'conv-thread', 'brian@airbnb.test', ['subject' => 'Pilot scope', 'snippet' => 'First pass at scope', 'sent_at' => now()->subHours(3)]);
    EmailBody::factory()->create(['email_id' => $first->id, 'body_html' => null, 'body_text' => 'Body of the first message']);
    $reply = conversationsPageMessage($this->ownerMailbox, 'conv-thread', 'ana@acme.test', ['subject' => 'Re: Pilot scope', 'snippet' => 'Looks good', 'sent_at' => now()->subHours(2)]);
    EmailBody::factory()->create(['email_id' => $reply->id, 'body_html' => null, 'body_text' => 'Body of the reply']);
    $last = conversationsPageMessage($this->ownerMailbox, 'conv-thread', 'brian@airbnb.test', ['subject' => 'Re: Pilot scope', 'snippet' => 'One more change', 'sent_at' => now()->subHour(), 'in_reply_to' => $first->rfc_message_id]);
    EmailBody::factory()->create(['email_id' => $last->id, 'body_html' => null, 'body_text' => 'One more change']);

    livewire(EmailInboxPage::class)
        ->call('selectEmail', $last->id)
        ->assertSet('expandedEmailIds', [$first->id, $last->id])
        ->assertSeeInOrder(['Body of the first message', 'Looks good', 'One more change'])
        ->assertDontSee('Body of the reply')
        ->call('expandMessage', $reply->id)
        ->assertSee('Body of the reply');
});

it('marks read only the messages the viewer can open', function (): void {
    $earlier = conversationsPageMessage($this->ownerMailbox, 'conv-mixed-tier', 'brian@airbnb.test', ['sent_at' => now()->subHours(2)]);
    $mine = conversationsPageMessage($this->ownerMailbox, 'conv-mixed-tier', 'brian@airbnb.test', ['sent_at' => now()->subHour()]);
    $bens = conversationsPageMessage($this->teammateMailbox, 'conv-mixed-tier', 'olivia@airbnb.test', ['snippet' => 'Clause 7 is a blocker', 'privacy_tier' => EmailPrivacyTier::METADATA_ONLY]);
    EmailBody::factory()->create(['email_id' => $bens->id, 'body_html' => null, 'body_text' => 'Clause 7 in full']);
    $this->company->emails()->attach([$mine->id, $bens->id]);

    $page = livewire(EmailInboxPage::class)
        ->call('selectEmail', $bens->id)
        ->assertDontSee('Clause 7 is a blocker')
        ->set('expandedEmailIds', [$bens->id])
        ->assertDontSee('Clause 7 in full');

    expect($page->instance()->expandedBodies->keys()->all())->toBe([]);

    expect(EmailRead::query()->where('user_id', $this->owner->id)->pluck('email_id')->sort()->values()->all())
        ->toBe(collect([$earlier->id, $mine->id])->sort()->values()->all());
});

it('expands only messages of the open conversation', function (): void {
    $open = conversationsPageMessage($this->ownerMailbox, 'conv-open', 'brian@airbnb.test');
    $elsewhere = conversationsPageMessage($this->ownerMailbox, 'conv-elsewhere', 'kim@notion.test');
    EmailBody::factory()->create(['email_id' => $elsewhere->id, 'body_html' => null, 'body_text' => 'Another conversation body']);

    $page = livewire(EmailInboxPage::class)
        ->call('selectEmail', $open->id)
        ->call('expandMessage', $elsewhere->id)
        ->assertSet('expandedEmailIds', [$open->id])
        ->assertDontSee('Another conversation body')
        ->set('expandedEmailIds', [$elsewhere->id])
        ->assertDontSee('Another conversation body');

    expect($page->instance()->expandedBodies->keys()->all())->toBe([$open->id]);
});

it('shows the gate for a locked message expanded inside a readable conversation', function (): void {
    $mine = conversationsPageMessage($this->ownerMailbox, 'conv-locked-inside', 'brian@airbnb.test', ['sent_at' => now()->subHour()]);
    $bens = conversationsPageMessage($this->teammateMailbox, 'conv-locked-inside', 'olivia@airbnb.test', [
        'snippet' => 'Clause 7 is a blocker',
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        'sent_at' => now()->subHours(2),
    ]);
    EmailBody::factory()->create(['email_id' => $bens->id, 'body_html' => null, 'body_text' => 'Clause 7 is a blocker in full']);
    $this->company->emails()->attach([$mine->id, $bens->id]);

    livewire(EmailInboxPage::class)
        ->call('selectEmail', $mine->id)
        ->assertSeeHtml("thread-row-{$bens->id}")
        ->assertDontSee('Clause 7 is a blocker')
        ->assertDontSeeHtml("data-reader-gate=\"{$bens->id}\"")
        ->call('expandMessage', $bens->id)
        ->assertSeeHtml("data-reader-gate=\"{$bens->id}\"")
        ->assertDontSee('Clause 7 is a blocker');
});

it('expands and replies to the newest message the viewer can read when the newest is locked', function (): void {
    $parent = conversationsPageMessage($this->ownerMailbox, 'conv-locked-newest', 'brian@airbnb.test', ['sent_at' => now()->subHours(3)]);
    $middle = conversationsPageMessage($this->ownerMailbox, 'conv-locked-newest', 'kim@airbnb.test', ['sent_at' => now()->subHours(2)]);
    $mine = conversationsPageMessage($this->ownerMailbox, 'conv-locked-newest', 'brian@airbnb.test', ['sent_at' => now()->subHour(), 'in_reply_to' => $parent->rfc_message_id]);
    $bens = conversationsPageMessage($this->teammateMailbox, 'conv-locked-newest', 'olivia@airbnb.test', ['privacy_tier' => EmailPrivacyTier::METADATA_ONLY]);
    $this->company->emails()->attach([$mine->id, $bens->id]);

    livewire(EmailInboxPage::class)
        ->call('selectEmail', $bens->id)
        ->assertSet('expandedEmailIds', [$parent->id, $mine->id, $bens->id])
        ->assertSeeHtml("thread-row-{$middle->id}")
        ->assertSeeHtml("data-reader-action=\"reply\" x-on:click=\"\$dispatch('composer:reply', { emailId: '{$mine->id}'")
        ->assertSeeHtmlInOrder([
            "data-thread-message=\"{$mine->id}\"",
            "wire:key=\"inline-reply-composer-{$bens->id}\"",
            "data-reader-gate=\"{$bens->id}\"",
        ]);
});

it('opens the drawer from the URL on the Emails page and clears it on close', function (): void {
    $email = conversationsPageMessage($this->ownerMailbox, 'conv-url', 'brian@airbnb.test', ['subject' => 'Quarterly review']);

    $page = Livewire::withQueryParams(['email' => $email->id, 'view' => 'all'])
        ->test(EmailInboxPage::class)
        ->assertSeeHtml('fi-email-reader-drawer')
        ->assertSee('Quarterly review');

    expect($page->effects['url']['selectedEmailId']['use'])->toBe('replace');

    $page->call('deselectEmail')
        ->assertSet('selectedEmailId', null)
        ->assertSet('conversationView', ConversationView::All)
        ->assertDispatched('composer:dismiss-inline')
        ->assertDontSeeHtml('fi-email-reader-drawer');
});

it('opens the email the bell names in the page drawer and keeps the view', function (): void {
    $email = conversationsPageMessage($this->ownerMailbox, 'conv-bell', 'brian@airbnb.test', ['subject' => 'Renewal terms']);
    $hidden = conversationsPageMessage($this->teammateMailbox, 'conv-hidden', 'olivia@stripe.test', ['subject' => 'Ben side project']);

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::All->value)
        ->dispatch('email:open', emailId: $email->id)
        ->assertSet('selectedEmailId', $email->id)
        ->assertSet('conversationView', ConversationView::All)
        ->assertSee('Renewal terms')
        ->dispatch('email:open', emailId: $hidden->id)
        ->assertSee(__('filament/pages/email-inbox.drawer.not_available'))
        ->assertDontSee('Ben side project');
});

it('saves the reply draft when the client clears the open email', function (): void {
    $email = conversationsPageMessage($this->ownerMailbox, 'conv-back', 'brian@airbnb.test');

    livewire(EmailInboxPage::class)
        ->call('selectEmail', $email->id)
        ->set('selectedEmailId', null)
        ->assertDispatched('composer:dismiss-inline')
        ->assertDontSeeHtml('fi-email-reader-drawer');
});

it('moves to the next and previous conversation in list order', function (): void {
    $newest = conversationsPageMessage($this->ownerMailbox, 'conv-a', 'a@client.test', ['sent_at' => now()->subMinute()]);
    $middle = conversationsPageMessage($this->ownerMailbox, 'conv-b', 'b@client.test', ['sent_at' => now()->subMinutes(2)]);
    $oldest = conversationsPageMessage($this->ownerMailbox, 'conv-c', 'c@client.test', ['sent_at' => now()->subMinutes(3)]);

    $page = livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::All->value)
        ->call('selectEmail', $middle->id)
        ->call('openAdjacentConversation', 'sideways')
        ->assertSet('selectedEmailId', $middle->id)
        ->call('openAdjacentConversation', 'next')
        ->assertSet('selectedEmailId', $oldest->id)
        ->call('openAdjacentConversation', 'next')
        ->assertSet('selectedEmailId', $oldest->id);

    preg_match_all('/data-conversation="([^"]+)"[^>]*aria-current="true"/', $page->html(), $current);
    expect($current[1])->toBe(['conv-c'])
        ->and($page->html())->toMatch('/data-reader-action="next"\s+x-on:click="[^"]*"\s+disabled\s/')
        ->not->toMatch('/data-reader-action="previous"\s+x-on:click="[^"]*"\s+disabled\s/');

    $page->call('openAdjacentConversation', 'previous')
        ->call('openAdjacentConversation', 'previous')
        ->assertSet('selectedEmailId', $newest->id);

    expect($page->html())->toMatch('/data-reader-action="previous"\s+x-on:click="[^"]*"\s+disabled\s/');
});

it('opens the viewer own preferred copy when the bell names another copy they may see', function (): void {
    $carol = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($carol, ['role' => 'member']);
    $bens = conversationsPageMessage($this->teammateMailbox, 'conv-shared-copy', 'olivia@airbnb.test', [
        'subject' => 'Shared renewal',
        'rfc_message_id' => '<renewal@airbnb.test>',
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
    ]);
    EmailShare::factory()->create(['email_id' => $bens->id, 'shared_with' => $this->owner->id, 'shared_by' => $this->teammate->id, 'tier' => EmailPrivacyTier::FULL]);
    $carols = conversationsPageMessage(conversationsPageMailbox($carol, 'carol@acme.test'), 'conv-shared-copy', 'olivia@airbnb.test', [
        'subject' => 'Shared renewal',
        'rfc_message_id' => '<renewal@airbnb.test>',
    ]);
    $this->company->emails()->attach($carols->id);
    $preferred = resolve(ConversationsQuery::class)->messages($this->owner, 'conv-shared-copy')->sole();

    expect($preferred->id)->toBe($carols->id);

    livewire(EmailInboxPage::class)
        ->dispatch('email:open', emailId: $bens->id)
        ->assertSet('selectedEmailId', $carols->id)
        ->assertSeeHtml('fi-email-reader-drawer')
        ->assertSee('Shared renewal')
        ->assertDontSee(__('filament/pages/email-inbox.drawer.not_available'));
});

it('shows the subject gate when a share opens the subject of a who and when email', function (): void {
    $bens = conversationsPageMessage($this->teammateMailbox, 'conv-subject-share', 'olivia@airbnb.test', [
        'subject' => 'Contract redlines',
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
    ]);
    EmailShare::factory()->create(['email_id' => $bens->id, 'shared_with' => $this->owner->id, 'shared_by' => $this->teammate->id, 'tier' => EmailPrivacyTier::SUBJECT]);

    livewire(EmailInboxPage::class)
        ->call('selectEmail', $bens->id)
        ->assertSee(__('filament/pages/email-inbox.drawer.gate.subject.heading'))
        ->assertDontSee(__('filament/pages/email-inbox.drawer.gate.metadata_only.heading'))
        ->assertSee('Contract redlines');
});

it('shows the not available line on the page for mail no conversation holds yet', function (): void {
    $unkeyed = conversationsPageMessage($this->ownerMailbox, 'conv-unkeyed', 'brian@airbnb.test', ['subject' => 'Unkeyed renewal']);
    $unkeyed->forceFill(['conversation_key' => null])->saveQuietly();

    Livewire::withQueryParams(['email' => $unkeyed->id])
        ->test(EmailInboxPage::class)
        ->assertSet('selectedEmailId', $unkeyed->id)
        ->assertSee(__('filament/pages/email-inbox.drawer.not_available'))
        ->assertDontSee('Unkeyed renewal');
});

/**
 * @return list<string>
 */
function conversationsPageFilteredKeys(User $viewer, ConversationFilters $filters): array
{
    return array_map(
        fn (object $row): string => $row->conversationKey,
        resolve(ConversationsQuery::class)->paginate($viewer, ConversationView::All, $filters)->rows,
    );
}

it('turns from and has attachment operators into filters and keeps the rest as words', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-match', 'brian@airbnb.com', ['subject' => 'Pricing for foo:bar rollout', 'has_attachments' => true]);
    conversationsPageMessage($this->ownerMailbox, 'conv-no-attachment', 'brian@airbnb.com', ['subject' => 'Pricing for foo:bar rollout', 'has_attachments' => false]);
    conversationsPageMessage($this->ownerMailbox, 'conv-other-sender', 'olivia@stripe.com', ['subject' => 'Pricing for foo:bar rollout', 'has_attachments' => true]);

    $filters = (new ConversationFilters)->withTyped('from:airbnb.com has:attachment pricing foo:bar', 'UTC');

    expect($filters->from)->toBe('airbnb.com')
        ->and($filters->hasAttachment)->toBeTrue()
        ->and($filters->search)->toBe('pricing foo:bar')
        ->and(conversationsPageFilteredKeys($this->owner, $filters))->toBe(['conv-match']);
});

it('keeps an invalid date operator as free text', function (): void {
    $invalid = (new ConversationFilters)->withTyped('after:soon pricing', 'America/Los_Angeles');
    $valid = (new ConversationFilters)->withTyped('after:2026-10-01 pricing', 'America/Los_Angeles');

    expect($invalid->sentAfter)->toBeNull()
        ->and($invalid->search)->toBe('after:soon pricing')
        ->and($valid->sentAfter?->utc()->toDateTimeString())->toBe('2026-10-01 07:00:00')
        ->and($valid->search)->toBe('pricing');
});

it('rejects an unknown filter field with a validation message', function (array $tree, string $path, string $message): void {
    expect(fn (): ConversationFilters => ConversationFilters::fromTree($tree, 'UTC'))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toBe([$path => [$message]]));
})->with([
    'unknown field' => fn (): array => [
        ['subject' => ['$contains' => 'x']],
        'filter.subject',
        __('validation.filter.unknown_name', ['name' => 'subject', 'available' => implode(', ', EmailFilter::fields())]),
    ],
    'unsupported operator' => fn (): array => [
        ['from' => ['$eq' => 'x']],
        'filter.from.$eq',
        __('validation.filter.unsupported_operator', ['name' => 'from', 'operator' => '$eq', 'supported' => '$contains']),
    ],
    'invalid direction' => fn (): array => [
        ['direction' => ['$in' => ['sideways']]],
        'filter.direction.$in',
        __('validation.filter.enum_value', ['name' => 'direction $in', 'value' => 'sideways', 'values' => 'inbound, outbound']),
    ],
]);

it('reads back the filter tree it writes', function (): void {
    $tree = [
        'mailbox' => ['$in' => [$this->ownerMailbox->id]],
        'from' => ['$contains' => 'airbnb.com'],
        'has_attachment' => ['$eq' => true],
        'sent_at' => ['$gte' => '2026-10-01T00:00:00-07:00', '$lt' => '2026-10-08T00:00:00-07:00'],
        'record' => ['company' => ['$in' => [$this->company->id]]],
    ];

    expect(ConversationFilters::fromTree($tree, 'America/Los_Angeles')->toTree())->toEqual($tree);
});

it('does not match another member\'s bcc address through to', function (): void {
    $teammateCopy = conversationsPageMessage($this->teammateMailbox, 'conv-bcc', 'olivia@stripe.com');
    EmailParticipant::factory()->to()->create(['email_id' => $teammateCopy->id, 'email_address' => 'customer@stripe.com']);
    EmailParticipant::factory()->bcc()->create(['email_id' => $teammateCopy->id, 'email_address' => 'secret@stripe.com']);
    $this->company->emails()->attach($teammateCopy->id);

    $to = fn (User $viewer, string $address): array => conversationsPageFilteredKeys($viewer, (new ConversationFilters)->withTyped("to:{$address}", 'UTC'));

    expect($to($this->owner, 'customer@stripe.com'))->toBe(['conv-bcc'])
        ->and($to($this->owner, 'secret@stripe.com'))->toBe([])
        ->and($to($this->teammate, 'secret@stripe.com'))->toBe(['conv-bcc']);
});

it('filters to one company through company:', function (): void {
    $stripe = Company::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Stripe']);
    $foreign = Company::factory()->create(['name' => 'Airbnb Labs']);

    $this->company->emails()->attach(conversationsPageMessage($this->ownerMailbox, 'conv-airbnb', 'brian@airbnb.com')->id);
    $stripe->emails()->attach(conversationsPageMessage($this->ownerMailbox, 'conv-stripe', 'olivia@stripe.com')->id);
    $foreign->emails()->attach(conversationsPageMessage($this->ownerMailbox, 'conv-foreign-link', 'anna@labs.test')->id);

    expect(conversationsPageFilteredKeys($this->owner, (new ConversationFilters)->withTyped('company:air', 'UTC')))->toBe(['conv-airbnb'])
        ->and(conversationsPageFilteredKeys($this->owner, ConversationFilters::fromTree(['record' => ['company' => ['$in' => [$foreign->id]]]], 'UTC')))->toBe([]);
});

it('finds a conversation on the page by a body word and keeps the search in the url', function (): void {
    $match = conversationsPageMessage($this->ownerMailbox, 'conv-kumquat', 'brian@airbnb.com', ['subject' => 'Orchard update']);
    EmailBody::create(['email_id' => $match->id, 'body_html' => null, 'body_text' => 'The kumquat harvest is late']);
    conversationsPageMessage($this->ownerMailbox, 'conv-other', 'olivia@stripe.com', ['subject' => 'Pricing']);

    $page = livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::All->value)
        ->set('search', 'kumquat');

    expect(conversationsPageKeys($page->html()))->toBe(['conv-kumquat']);
    $page->assertSet('search', 'kumquat');
});

it('turns typed operators into chips on submit and back into text when a chip is edited', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-airbnb', 'brian@airbnb.com', ['subject' => 'Pricing for 40 seats']);
    conversationsPageMessage($this->ownerMailbox, 'conv-stripe', 'olivia@stripe.com', ['subject' => 'Pricing for 40 seats']);

    $page = livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::All->value)
        ->set('search', 'from:airbnb.com pricing')
        ->call('submitSearch')
        ->assertSet('filter', ['from' => ['$contains' => 'airbnb.com']])
        ->assertSet('search', 'pricing')
        ->assertSeeHtml('data-filter-chip="from"');

    expect(conversationsPageKeys($page->html()))->toBe(['conv-airbnb']);

    $page->call('editFilter', 'from')
        ->assertSet('filter', [])
        ->assertSet('search', 'pricing from:airbnb.com');
});

it('rejects an invalid filter in the url with a validation message and lists as if no filter were set', function (mixed $filter, string $message): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-one', 'brian@airbnb.com');
    conversationsPageMessage($this->ownerMailbox, 'conv-two', 'olivia@stripe.com');

    $page = Livewire::withQueryParams(['view' => ConversationView::All->value, 'filter' => $filter])
        ->test(EmailInboxPage::class)
        ->assertOk()
        ->assertSeeHtml('data-filter-error')
        ->assertSee($message);

    expect(conversationsPageKeys($page->html()))->toEqualCanonicalizing(['conv-one', 'conv-two']);
})->with([
    'unknown field' => fn (): array => [
        ['subject' => ['$contains' => 'x']],
        __('validation.filter.unknown_name', ['name' => 'subject', 'available' => implode(', ', EmailFilter::fields())]),
    ],
    'not an object' => fn (): array => ['abc', __('validation.filter.not_object')],
    'not a ulid' => fn (): array => [
        ['mailbox' => ['$in' => ['not-a-ulid']]],
        __('validation.filter.record_id', ['name' => 'mailbox $in', 'value' => 'not-a-ulid']),
    ],
]);

it('narrows the list through the mailbox, date, linked record and category chips', function (): void {
    $old = conversationsPageMessage($this->ownerMailbox, 'conv-old', 'brian@airbnb.com', ['sent_at' => now()->subDays(20)]);
    $new = conversationsPageMessage($this->ownerMailbox, 'conv-new', 'olivia@stripe.com', ['sent_at' => now()->subDay()]);
    $this->company->emails()->attach($old->id);
    EmailLabel::query()->create(['email_id' => $new->id, 'label' => EmailCategory::Invoice->value, 'source' => 'system', 'created_at' => now()]);

    $this->company->emails()->attach(conversationsPageMessage($this->teammateMailbox, 'conv-teammate', 'kai@airbnb.com', ['sent_at' => now()->subDays(2)])->id);

    $keys = fn (Testable $page): array => conversationsPageKeys($page->html());
    $page = livewire(EmailInboxPage::class)->call('setView', ConversationView::All->value);
    expect($keys($page))->toEqualCanonicalizing(['conv-old', 'conv-new', 'conv-teammate']);

    $page->callAction('mailboxFilter', ['mailboxes' => [$this->ownerMailbox->id]])->assertHasNoActionErrors();
    expect($keys($page))->toEqualCanonicalizing(['conv-old', 'conv-new']);

    $page->callAction('categoryFilter', ['categories' => [EmailCategory::Invoice->value]]);
    expect($keys($page))->toBe(['conv-new']);

    $page->call('removeFilter', 'category')
        ->callAction('recordFilter', ['records' => ["company:{$this->company->id}"]])
        ->assertSeeHtml('data-filter-chip="record"')
        ->assertSee('Airbnb');
    expect($keys($page))->toBe(['conv-old']);

    $page->call('removeFilter', 'record')
        ->callAction('sentAtFilter', ['after' => now()->subDays(5)->format('Y-m-d'), 'before' => null]);
    expect($keys($page))->toBe(['conv-new']);
});

it('refuses linked records of two types in one chip', function (): void {
    $person = People::factory()->create(['workspace_id' => $this->workspace->id]);

    livewire(EmailInboxPage::class)
        ->callAction('recordFilter', ['records' => ["company:{$this->company->id}", "people:{$person->id}"]])
        ->assertHasActionErrors(['records'])
        ->assertSet('filter', []);
});

it('toggles the attachment chip and clears every chip at once', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-file', 'brian@airbnb.com', ['has_attachments' => true]);
    conversationsPageMessage($this->ownerMailbox, 'conv-plain', 'olivia@stripe.com');

    $page = livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::All->value)
        ->call('toggleAttachmentFilter')
        ->assertSet('filter', ['has_attachment' => ['$eq' => true]]);
    expect(conversationsPageKeys($page->html()))->toBe(['conv-file']);

    $page->set('search', 'from:stripe.com')->call('submitSearch')
        ->assertSee(__('filament/pages/email-inbox.filters.clear_all'))
        ->call('clearFilters')
        ->assertSet('filter', [])
        ->assertSet('search', '');
    expect(conversationsPageKeys($page->html()))->toEqualCanonicalizing(['conv-file', 'conv-plain']);
});

it('says what search covers when nothing matches', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-one', 'brian@airbnb.com');

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::All->value)
        ->set('search', 'zebra')
        ->assertSee(__('filament/pages/email-inbox.views.no_match'))
        ->assertSee(__('filament/pages/email-inbox.views.no_match_hint'));
});

it('ignores a list in the q parameter of the url', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-one', 'brian@airbnb.com');

    $this->get(EmailInboxPage::getUrl(['view' => ConversationView::All->value, 'q' => ['x']], tenant: $this->workspace))
        ->assertOk()
        ->assertSee('data-conversation="conv-one"', false);
});

it('ends a typed before day at midnight in the viewer zone and a picked before day after it', function (): void {
    $this->owner->forceFill(['timezone' => 'America/Los_Angeles'])->save();
    $zone = 'America/Los_Angeles';
    conversationsPageMessage($this->ownerMailbox, 'conv-late-seventh', 'brian@airbnb.com', ['sent_at' => Date::parse('2026-10-07 23:30', $zone)->utc()]);
    conversationsPageMessage($this->ownerMailbox, 'conv-early-eighth', 'olivia@stripe.com', ['sent_at' => Date::parse('2026-10-08 00:30', $zone)->utc()]);

    expect(conversationsPageFilteredKeys($this->owner, (new ConversationFilters)->withTyped('before:2026-10-08', $zone)))->toBe(['conv-late-seventh']);

    $page = livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::All->value)
        ->callAction('sentAtFilter', ['after' => '2026-10-08', 'before' => '2026-10-08'])
        ->assertHasNoActionErrors();

    expect(conversationsPageKeys($page->html()))->toBe(['conv-early-eighth']);
    $page->assertSee(__('filament/pages/email-inbox.filters.on_or_before', ['date' => 'Oct 8, 2026']))
        ->assertDontSee('Oct 9, 2026');
});

it('labels a before bound that is not a local midnight with the instant it names', function (): void {
    $this->owner->forceFill(['timezone' => 'America/Los_Angeles'])->save();

    Livewire::withQueryParams(['view' => ConversationView::All->value, 'filter' => ['sent_at' => ['$lt' => '2026-10-08T12:00:00-07:00']]])
        ->test(EmailInboxPage::class)
        ->assertSee(__('filament/pages/email-inbox.filters.before', ['date' => 'Oct 8, 2026']))
        ->assertDontSee(__('filament/pages/email-inbox.filters.on_or_before', ['date' => 'Oct 7, 2026']));
});

it('finds a teammate conversation through each of subject, body and participant', function (): void {
    $bySubject = conversationsPageMessage($this->teammateMailbox, 'conv-subject', 'olivia@stripe.test', ['subject' => 'Orchard plan', 'snippet' => 'Hello']);
    $byBody = conversationsPageMessage($this->teammateMailbox, 'conv-body', 'kai@stripe.test', ['subject' => 'Update', 'snippet' => 'Hello']);
    EmailBody::create(['email_id' => $byBody->id, 'body_html' => null, 'body_text' => 'The orchard is late']);
    $byParticipant = conversationsPageMessage($this->teammateMailbox, 'conv-participant', 'orchard@farm.test', ['subject' => 'Update', 'snippet' => 'Hello']);
    conversationsPageMessage($this->teammateMailbox, 'conv-none', 'lena@stripe.test', ['subject' => 'Update', 'snippet' => 'Hello']);
    $this->company->emails()->attach([$bySubject->id, $byBody->id, $byParticipant->id]);

    expect(conversationsPageFilteredKeys($this->owner, new ConversationFilters(search: 'orchard')))
        ->toEqualCanonicalizing(['conv-subject', 'conv-body', 'conv-participant']);
});

it('resolves a typed company prefix to the first matching names in order', function (): void {
    Company::factory()->count(CustomFieldFilterSchema::MAX_LIST_VALUES)
        ->sequence(fn (Sequence $sequence): array => ['name' => sprintf('Acme %03d', $sequence->index)])
        ->create(['workspace_id' => $this->workspace->id]);
    $first = Company::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Aaa Holdings']);
    $first->emails()->attach(conversationsPageMessage($this->ownerMailbox, 'conv-first', 'brian@aaa.com')->id);

    expect(conversationsPageFilteredKeys($this->owner, (new ConversationFilters)->withTyped('company:a', 'UTC')))->toBe(['conv-first']);
});

it('counts each view badge through the active search', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'brian@airbnb.com', ['subject' => 'Pricing']);
    conversationsPageMessage($this->ownerMailbox, 'conv-lunch', 'olivia@stripe.com', ['subject' => 'Lunch']);

    $page = livewire(EmailInboxPage::class);
    expect($page->instance()->viewCounts['needs_reply'])->toBe(2);

    $page->set('search', 'pricing')->assertSet('conversationView', ConversationView::NeedsReply);
    expect($page->instance()->viewCounts['needs_reply'])->toBe(1)
        ->and(conversationsPageKeys($page->html()))->toBe(['conv-pricing']);
});

it('matches a one character last word as a whole word, not a prefix', function (): void {
    foreach (['conv-k' => 'Orchard k plan', 'conv-kumquat' => 'Orchard kumquat plan'] as $key => $text) {
        $email = conversationsPageMessage($this->ownerMailbox, $key, 'brian@airbnb.com', ['subject' => 'Update']);
        EmailBody::create(['email_id' => $email->id, 'body_html' => null, 'body_text' => $text]);
    }

    expect(conversationsPageFilteredKeys($this->owner, new ConversationFilters(search: 'orchard k')))->toBe(['conv-k']);
});

it('drops the opening quote of an unclosed typed value', function (): void {
    $filters = (new ConversationFilters)->withTyped('to:"Ana pricing', 'UTC');

    expect($filters->to)->toBe('Ana')
        ->and($filters->search)->toBe('pricing');
});

it('adds a new conversation to needs reply when it is refreshed', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-first', 'kim@notion.test', ['sent_at' => now()->subHour()]);
    $page = livewire(EmailInboxPage::class);

    Event::fake([ConversationChanged::class]);
    conversationsPageMessage($this->ownerMailbox, 'conv-new', 'brian@airbnb.test');

    expect(livewire(EmailInboxPage::class)->instance()->viewCounts['needs_reply'])->toBe(1);

    $page->call('refreshConversations');

    expect(conversationsPageKeys($page->html()))->toBe(['conv-new', 'conv-first'])
        ->and($page->instance()->viewCounts['needs_reply'])->toBe(2)
        ->and($page->instance()->activeView)->toBe(ConversationView::NeedsReply);
});

it('removes a row the viewer may no longer see', function (): void {
    $email = conversationsPageMessage($this->teammateMailbox, 'conv-shared', 'brian@airbnb.test', ['privacy_tier' => EmailPrivacyTier::METADATA_ONLY]);
    $this->company->emails()->attach($email->id);
    $page = livewire(EmailInboxPage::class)->call('setView', ConversationView::All->value);

    expect(conversationsPageKeys($page->html()))->toBe(['conv-shared']);

    resolve(UpdateEmailSharingAction::class)->execute($email->fresh(), $this->teammate, EmailPrivacyTier::PRIVATE, []);
    $page->call('refreshConversations');

    expect(conversationsPageKeys($page->html()))->toBe([]);
});

it('ignores a change in another workspace', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-own', 'kim@notion.test');
    $page = livewire(EmailInboxPage::class);

    $outsider = User::factory()->withWorkspace()->create();
    conversationsPageMessage(conversationsPageMailbox($outsider, 'eve@other.test'), 'conv-outside', 'brian@airbnb.test');
    $page->call('refreshConversations')->assertHasNoErrors();

    expect(conversationsPageKeys($page->html()))->toBe(['conv-own'])
        ->and($page->instance()->viewCounts['needs_reply'])->toBe(1);
});

it('keeps cached counts apart for each search and each workspace', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'brian@airbnb.test');
    conversationsPageMessage($this->ownerMailbox, 'conv-lunch', 'kim@notion.test');

    expect(livewire(EmailInboxPage::class)->instance()->viewCounts['needs_reply'])->toBe(2)
        ->and(Livewire::withQueryParams(['q' => 'kim@notion.test'])->test(EmailInboxPage::class)->instance()->viewCounts['needs_reply'])->toBe(1);

    $elsewhere = Workspace::factory()->create(['user_id' => $this->owner->id]);
    config(['relaticle.features.email_conversations' => [$this->workspace->id, $elsewhere->id]]);
    $this->owner->forceFill(['current_workspace_id' => $elsewhere->id])->save();
    Filament::setTenant($elsewhere);

    expect(livewire(EmailInboxPage::class)->instance()->viewCounts['needs_reply'])->toBe(0);
});

it('forgets the cached counts of the workspace members when a conversation changes', function (): void {
    resolve(ConversationsQuery::class)->counts($this->teammate);

    expect(Cache::has(ConversationsQuery::countsCacheKey($this->teammate)))->toBeTrue();

    conversationsPageMessage($this->ownerMailbox, 'conv-new', 'brian@airbnb.test');

    expect(Cache::has(ConversationsQuery::countsCacheKey($this->teammate)))->toBeFalse();
});

/**
 * @return list<string>
 */
function conversationsPageViewKeys(ConversationView $view): array
{
    return conversationsPageKeys(livewire(EmailInboxPage::class)->call('setView', $view->value)->html());
}

it('brings a done conversation back to needs reply when the customer writes again', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00'));
    conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'brian@airbnb.test', ['sent_at' => now()->subHour()]);

    livewire(EmailInboxPage::class)->call('markDone', conversationsPageSeen(['conv-pricing']));

    expect(conversationsPageViewKeys(ConversationView::NeedsReply))->toBe([])
        ->and(conversationsPageViewKeys(ConversationView::Done))->toBe(['conv-pricing'])
        ->and(livewire(EmailInboxPage::class)->instance()->viewCounts['needs_reply'])->toBe(0);

    $this->travel(1)->hour();
    conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'brian@airbnb.test', ['in_reply_to' => '<pricing-1@airbnb.test>']);

    expect(conversationsPageViewKeys(ConversationView::NeedsReply))->toBe(['conv-pricing'])
        ->and(conversationsPageViewKeys(ConversationView::Done))->toBe([]);
});

it('keeps a conversation done when a member replies', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00'));
    conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'brian@airbnb.test', ['sent_at' => now()->subHour()]);

    livewire(EmailInboxPage::class)->call('markDone', conversationsPageSeen(['conv-pricing']));

    $this->travel(1)->hour();
    conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'ana@acme.test');
    $this->travel(1)->hour();
    conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'ben@acme.test');

    expect(conversationsPageViewKeys(ConversationView::NeedsReply))->toBe([])
        ->and(conversationsPageViewKeys(ConversationView::Done))->toBe(['conv-pricing']);
});

it('keeps a conversation done when an automated message arrives', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00'));
    conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'brian@airbnb.test', ['sent_at' => now()->subHour()]);

    livewire(EmailInboxPage::class)->call('markDone', conversationsPageSeen(['conv-pricing']));

    $this->travel(1)->hour();
    conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'updates@airbnb.test', ['automated_reason' => AutomatedReason::List]);

    expect(conversationsPageViewKeys(ConversationView::NeedsReply))->toBe([])
        ->and(conversationsPageViewKeys(ConversationView::Done))->toBe(['conv-pricing']);
});

it('does not share done between two members holding the same conversation', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00'));
    conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'brian@airbnb.test', ['rfc_message_id' => '<pricing-1@airbnb.test>', 'sent_at' => now()->subHour()]);
    conversationsPageMessage($this->teammateMailbox, 'conv-pricing', 'brian@airbnb.test', ['rfc_message_id' => '<pricing-1@airbnb.test>', 'sent_at' => now()->subHour()]);

    livewire(EmailInboxPage::class)->call('markDone', conversationsPageSeen(['conv-pricing']));

    expect(conversationsPageViewKeys(ConversationView::Done))->toBe(['conv-pricing']);

    actingAs($this->teammate);

    expect(conversationsPageViewKeys(ConversationView::NeedsReply))->toBe(['conv-pricing'])
        ->and(conversationsPageViewKeys(ConversationView::Done))->toBe([]);
});

it('refuses to mark a conversation done that the viewer cannot see', function (): void {
    conversationsPageMessage($this->teammateMailbox, 'conv-private', 'brian@airbnb.test', ['privacy_tier' => EmailPrivacyTier::PRIVATE]);

    livewire(EmailInboxPage::class)
        ->call('markDone', conversationsPageSeen(['conv-private']))
        ->assertReturned([
            'applied' => [],
            'skipped' => [['reason' => TriageSkipReason::NotAvailable->value, 'label' => TriageSkipReason::NotAvailable->label(), 'count' => 1]],
        ]);

    expect(EmailConversationState::query()->count())->toBe(0);
});

it('marks unread only the viewer\'s own read state', function (): void {
    $ownerCopy = conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'brian@airbnb.test', ['rfc_message_id' => '<pricing-1@airbnb.test>']);
    $teammateCopy = conversationsPageMessage($this->teammateMailbox, 'conv-pricing', 'brian@airbnb.test', ['rfc_message_id' => '<pricing-1@airbnb.test>']);
    EmailRead::factory()->create(['email_id' => $ownerCopy->id, 'user_id' => $this->owner->id, 'read_at' => now()]);
    EmailRead::factory()->create(['email_id' => $teammateCopy->id, 'user_id' => $this->teammate->id, 'read_at' => now()]);
    EmailRead::factory()->create(['email_id' => $ownerCopy->id, 'user_id' => $this->teammate->id, 'read_at' => now()]);

    livewire(EmailInboxPage::class)->call('markUnread', ['conv-pricing']);

    expect(EmailRead::query()->where('email_id', $ownerCopy->id)->where('user_id', $this->teammate->id)->exists())->toBeTrue()
        ->and(EmailRead::query()->where('email_id', $ownerCopy->id)->where('user_id', $this->owner->id)->exists())->toBeFalse();

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::All->value)
        ->assertSeeHtml('data-conversation="conv-pricing" data-unread="true"');

    actingAs($this->teammate);

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::All->value)
        ->assertSeeHtml('data-conversation="conv-pricing" data-unread="false"');
});

it('skips teammate copies that never reached the viewer when marking read', function (): void {
    $email = conversationsPageMessage($this->teammateMailbox, 'conv-ben-lead', 'olivia@stripe.test');
    $this->company->emails()->attach($email->id);

    livewire(EmailInboxPage::class)
        ->call('markRead', ['conv-ben-lead'])
        ->assertReturned([
            'applied' => [],
            'skipped' => [['reason' => TriageSkipReason::NotInYourMailbox->value, 'label' => TriageSkipReason::NotInYourMailbox->label(), 'count' => 1]],
        ]);

    expect(EmailRead::query()->count())->toBe(0);
});

it('reopens a done conversation', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'brian@airbnb.test');

    livewire(EmailInboxPage::class)
        ->call('markDone', conversationsPageSeen(['conv-pricing']))
        ->call('reopen', ['conv-pricing'])
        ->assertReturned(['applied' => ['conv-pricing'], 'skipped' => []]);

    expect(conversationsPageViewKeys(ConversationView::NeedsReply))->toBe(['conv-pricing'])
        ->and(conversationsPageViewKeys(ConversationView::Done))->toBe([]);
});

it('returns the skipped count to the page', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'brian@airbnb.test');
    conversationsPageMessage($this->teammateMailbox, 'conv-private', 'olivia@stripe.test', ['privacy_tier' => EmailPrivacyTier::PRIVATE]);

    livewire(EmailInboxPage::class)
        ->assertSeeHtml('data-triage-action="markDone"')
        ->call('markDone', conversationsPageSeen(['conv-pricing', 'conv-private', 'conv-pricing']))
        ->assertReturned([
            'applied' => ['conv-pricing'],
            'skipped' => [['reason' => TriageSkipReason::NotAvailable->value, 'label' => TriageSkipReason::NotAvailable->label(), 'count' => 1]],
        ]);

    expect(conversationsPageViewKeys(ConversationView::Done))->toBe(['conv-pricing']);

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::All->value)
        ->assertSeeHtml('data-message-count="1" data-done="true"');
});

it('brings a done conversation back when a reply sent before the mark syncs after it', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00'));
    conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'brian@airbnb.test');

    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:02'));
    livewire(EmailInboxPage::class)->call('markDone', conversationsPageSeen(['conv-pricing']));

    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:05'));
    conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'brian@airbnb.test', ['sent_at' => CarbonImmutable::parse('2026-11-02 10:01')]);

    expect(conversationsPageViewKeys(ConversationView::NeedsReply))->toBe(['conv-pricing'])
        ->and(conversationsPageViewKeys(ConversationView::Done))->toBe([]);
});

it('reopens only the viewer\'s own done state', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'brian@airbnb.test', ['rfc_message_id' => '<pricing-1@airbnb.test>']);
    conversationsPageMessage($this->teammateMailbox, 'conv-pricing', 'brian@airbnb.test', ['rfc_message_id' => '<pricing-1@airbnb.test>']);
    EmailConversationState::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->teammate->id,
        'conversation_key' => 'conv-pricing',
    ]);

    livewire(EmailInboxPage::class)
        ->call('markDone', conversationsPageSeen(['conv-pricing']))
        ->call('reopen', ['conv-pricing']);

    expect(EmailConversationState::query()->where('user_id', $this->owner->id)->exists())->toBeFalse()
        ->and(EmailConversationState::query()->where('user_id', $this->teammate->id)->where('conversation_key', 'conv-pricing')->exists())->toBeTrue();
});

it('writes no read row for a teammate copy shared below full', function (EmailPrivacyTier $tier): void {
    $email = conversationsPageMessage($this->teammateMailbox, 'conv-shared', 'olivia@stripe.test', ['privacy_tier' => EmailPrivacyTier::PRIVATE]);
    EmailShare::factory()->create([
        'email_id' => $email->id,
        'shared_by' => $this->teammate->id,
        'shared_with' => $this->owner->id,
        'tier' => $tier->value,
    ]);

    livewire(EmailInboxPage::class)
        ->call('markRead', ['conv-shared'])
        ->assertReturned([
            'applied' => [],
            'skipped' => [['reason' => TriageSkipReason::NotInYourMailbox->value, 'label' => TriageSkipReason::NotInYourMailbox->label(), 'count' => 1]],
        ]);

    expect(EmailRead::query()->count())->toBe(0);
})->with([EmailPrivacyTier::SUBJECT, EmailPrivacyTier::METADATA_ONLY]);

it('refuses more than one hundred conversations in one action', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'brian@airbnb.test');
    $keys = ['conv-pricing', ...array_map(fn (int $index): string => "conv-{$index}", range(1, 100))];

    livewire(EmailInboxPage::class)
        ->call('markDone', conversationsPageSeen($keys))
        ->assertHasErrors(['keys']);

    expect(EmailConversationState::query()->count())->toBe(0);
});

it('marks done in the current workspace when another holds the same key', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'brian@airbnb.test');
    $elsewhere = Workspace::factory()->create(['user_id' => $this->owner->id]);
    config(['relaticle.features.email_conversations' => [$this->workspace->id, $elsewhere->id]]);
    $this->owner->forceFill(['current_workspace_id' => $elsewhere->id])->save();
    Filament::setTenant($elsewhere);
    conversationsPageMessage(conversationsPageMailbox($this->owner, 'ana@elsewhere.test'), 'conv-pricing', 'brian@airbnb.test');

    livewire(EmailInboxPage::class)
        ->call('markDone', conversationsPageSeen(['conv-pricing']))
        ->assertReturned(['applied' => ['conv-pricing'], 'skipped' => []]);

    expect(EmailConversationState::query()->pluck('workspace_id')->all())->toBe([$elsewhere->id]);
});

it('keeps the needs reply view after the last conversation in it is marked done', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'brian@airbnb.test');

    $page = livewire(EmailInboxPage::class)->call('markDone', conversationsPageSeen(['conv-pricing']));

    expect($page->instance()->conversationView)->toBe(ConversationView::NeedsReply)
        ->and($page->instance()->activeView)->toBe(ConversationView::NeedsReply);
});

it('marks every selected conversation done in one call and reports the skipped ones', function (): void {
    foreach (['conv-pricing', 'conv-security', 'conv-renewal'] as $key) {
        conversationsPageMessage($this->ownerMailbox, $key, 'brian@airbnb.test');
    }
    conversationsPageMessage($this->teammateMailbox, 'conv-private', 'olivia@stripe.test', ['privacy_tier' => EmailPrivacyTier::PRIVATE]);

    livewire(EmailInboxPage::class)
        ->assertSeeHtml('data-select-page')
        ->assertSeeHtml('data-select-conversation')
        ->assertSeeHtml('data-triage-bar')
        ->call('markDone', conversationsPageSeen(['conv-pricing', 'conv-security', 'conv-renewal', 'conv-private']))
        ->assertReturned([
            'applied' => ['conv-pricing', 'conv-security', 'conv-renewal'],
            'skipped' => [['reason' => TriageSkipReason::NotAvailable->value, 'label' => TriageSkipReason::NotAvailable->label(), 'count' => 1]],
        ]);

    expect(conversationsPageViewKeys(ConversationView::Done))->toEqualCanonicalizing(['conv-pricing', 'conv-security', 'conv-renewal'])
        ->and(conversationsPageViewKeys(ConversationView::NeedsReply))->toBe([]);
});

it('brings a done conversation back when the summary gained a reply the row had not shown yet', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00'));
    conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'brian@airbnb.test');
    $seen = conversationsPageSeen(['conv-pricing']);

    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:01'));
    conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'brian@airbnb.test');

    livewire(EmailInboxPage::class)
        ->call('markDone', $seen)
        ->assertReturned(['applied' => ['conv-pricing'], 'skipped' => []]);

    expect($seen)->toBe(['conv-pricing' => '2026-11-02T10:00:00Z'])
        ->and(conversationsPageViewKeys(ConversationView::NeedsReply))->toBe(['conv-pricing'])
        ->and(conversationsPageViewKeys(ConversationView::Done))->toBe([]);
});

it('never stores a seen time past the summary', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00'));
    conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'brian@airbnb.test');

    livewire(EmailInboxPage::class)->call('markDone', ['conv-pricing' => '2027-01-01T00:00:00Z']);

    expect(EmailConversationState::query()->sole()->done_through?->toIso8601String())->toBe('2026-11-02T10:00:00+00:00');
});

it('refuses a seen time that is not a date', function (mixed $seen): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'brian@airbnb.test');

    livewire(EmailInboxPage::class)
        ->call('markDone', ['conv-pricing' => $seen])
        ->assertHasErrors(['keys']);

    expect(EmailConversationState::query()->count())->toBe(0);
})->with(['not a date' => 'soon-ish', 'empty' => '', 'a list' => [['2026-11-02']]]);

it('keeps a conversation with no inbound in done until external mail arrives', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00'));
    conversationsPageMessage($this->ownerMailbox, 'conv-intro', 'ana@acme.test');

    livewire(EmailInboxPage::class)->call('markDone', conversationsPageSeen(['conv-intro']));

    expect(EmailConversationState::query()->sole()->done_through)->toBeNull()
        ->and(conversationsPageViewKeys(ConversationView::Done))->toBe(['conv-intro']);

    $this->travelTo(CarbonImmutable::parse('2026-11-02 11:00'));
    conversationsPageMessage($this->ownerMailbox, 'conv-intro', 'brian@airbnb.test');

    expect(conversationsPageViewKeys(ConversationView::NeedsReply))->toBe(['conv-intro'])
        ->and(conversationsPageViewKeys(ConversationView::Done))->toBe([]);
});

it('marks done from the reader a conversation the list does not show', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00'));
    $email = conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'brian@airbnb.test');

    $page = livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::AwaitingReply->value)
        ->call('openRequestedEmail', $email->id);
    preg_match('/data-conversation-key="conv-pricing"\s+data-last-inbound="([^"]*)"/', $page->html(), $rendered);

    expect(conversationsPageKeys($page->html()))->toBe([])
        ->and($rendered[1] ?? null)->toBe('2026-11-02T10:00:00Z');

    $page->call('markDone', ['conv-pricing' => $rendered[1]])
        ->assertReturned(['applied' => ['conv-pricing'], 'skipped' => []]);

    expect(conversationsPageViewKeys(ConversationView::Done))->toBe(['conv-pricing'])
        ->and(conversationsPageViewKeys(ConversationView::NeedsReply))->toBe([]);
});

it('reads one conversation row as the list shows it to a teammate below full', function (EmailPrivacyTier $tier): void {
    $email = conversationsPageMessage($this->teammateMailbox, 'conv-shared', 'olivia@stripe.test', ['subject' => 'Security review findings', 'privacy_tier' => $tier]);
    $this->company->emails()->attach($email->id);
    $query = resolve(ConversationsQuery::class);

    $listed = $query->paginate($this->owner, ConversationView::All, new ConversationFilters)->rows[0];
    $row = $query->conversationRow($this->owner, 'conv-shared');

    expect($row?->subject)->toBe($listed->subject)
        ->and($row?->subject)->toBe($tier === EmailPrivacyTier::SUBJECT ? 'Security review findings' : null)
        ->and($row?->access)->toBe($listed->access)
        ->and($row?->viewerCopyEmailId)->toBe($listed->viewerCopyEmailId);
})->with([EmailPrivacyTier::METADATA_ONLY, EmailPrivacyTier::SUBJECT]);

it('reads no conversation row from another workspace', function (): void {
    $outsider = User::factory()->withWorkspace()->create();
    conversationsPageMessage(conversationsPageMailbox($outsider, 'eve@other.test'), 'conv-outside', 'brian@airbnb.test');

    expect(resolve(ConversationsQuery::class)->conversationRow($this->owner, 'conv-outside'))->toBeNull();
});

function unlinkedSenderAddresses(Testable $page): array
{
    preg_match_all('/data-unlinked-sender="([^"]+)"/', $page->html(), $matches);

    return $matches[1];
}

function unlinkedPersonAddresses(People $person): array
{
    $field = CustomField::query()->withoutGlobalScopes()
        ->where('tenant_id', $person->workspace_id)
        ->where('entity_type', 'people')
        ->where('code', 'emails')
        ->firstOrFail();

    return array_values([...$person->fresh()->load('customFieldValues.customField')->getCustomFieldValue($field) ?? []]);
}

it('groups the unlinked conversations of one sender into one row, the senders you wrote to first', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-brian-1', 'brian@airbnb.test', ['sent_at' => now()->subDays(2)]);
    conversationsPageMessage($this->ownerMailbox, 'conv-brian-2', 'Brian@Airbnb.test', ['sent_at' => now()->subDay()]);
    conversationsPageMessage($this->ownerMailbox, 'conv-kim', 'kim@notion.test', ['sent_at' => now()->subDays(5)]);
    conversationsPageMessage($this->ownerMailbox, 'conv-kim', 'ana@acme.test', ['sent_at' => now()->subDays(4)]);
    EmailParticipant::factory()->to()->create(['email_id' => Email::query()->where('conversation_key', 'conv-kim')->where('direction', 'outbound')->value('id'), 'email_address' => 'kim@notion.test']);

    $page = livewire(EmailInboxPage::class)->call('setView', ConversationView::Unlinked->value);

    expect(unlinkedSenderAddresses($page))->toBe(['kim@notion.test', 'brian@airbnb.test'])
        ->and($page->html())->toContain('2 conversations')
        ->and($page->html())->toContain('You wrote once');

    expect(conversationsPageKeys($page->call('toggleSender', 'brian@airbnb.test')->html()))->toBe(['conv-brian-2', 'conv-brian-1']);
});

it('groups a conversation the viewer only wrote in under its first outside recipient, so the badge counts the rows shown', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-brian', 'brian@airbnb.test', ['sent_at' => now()->subDay()]);
    $pitch = conversationsPageMessage($this->ownerMailbox, 'conv-pitch', $this->ownerMailbox->email_address);
    EmailParticipant::factory()->to()->create(['email_id' => $pitch->id, 'email_address' => $this->teammate->email]);
    EmailParticipant::factory()->to()->create(['email_id' => $pitch->id, 'email_address' => 'Ana@Acme.test', 'name' => 'Ana Lopez']);
    EmailParticipant::factory()->to()->create(['email_id' => $pitch->id, 'email_address' => 'zoe@acme.test']);

    $page = livewire(EmailInboxPage::class)->call('setView', ConversationView::Unlinked->value);
    $senders = unlinkedSenderAddresses($page);
    $rows = array_merge(...array_map(fn (string $address): array => conversationsPageKeys($page->call('toggleSender', $address)->html()), $senders));

    expect($senders)->toBe(['ana@acme.test', 'brian@airbnb.test'])
        ->and($rows)->toEqualCanonicalizing(['conv-pitch', 'conv-brian'])
        ->and($page->instance()->viewCounts['unlinked'])->toBe(count($rows))
        ->and(resolve(UnlinkedSendersQuery::class)->conversationKeys($this->owner, 'Ana@Acme.test'))->toBe(['conv-pitch'])
        ->and(resolve(UnlinkedSendersQuery::class)->conversationKeys($this->owner, 'zoe@acme.test'))->toBe([]);
});

it('groups an automated message in a conversation people answered, as the badge counts it', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-renewal', 'billing@stripe.test', ['automated_reason' => AutomatedReason::RoleSender]);
    conversationsPageMessage($this->teammateMailbox, 'conv-renewal', 'olivia@stripe.test');
    conversationsPageMessage($this->ownerMailbox, 'conv-receipts', 'receipts@stripe.test', ['automated_reason' => AutomatedReason::RoleSender]);

    $page = livewire(EmailInboxPage::class)->call('setView', ConversationView::Unlinked->value);

    expect(unlinkedSenderAddresses($page))->toBe(['billing@stripe.test'])
        ->and($page->instance()->viewCounts['unlinked'])->toBe(1);
});

it('leaves a teammate unlinked mail and linked conversations out of the sender groups', function (): void {
    conversationsPageMessage($this->teammateMailbox, 'conv-ben', 'olivia@stripe.test');
    $linked = conversationsPageMessage($this->ownerMailbox, 'conv-linked', 'kim@notion.test');
    $this->company->emails()->attach($linked->id);

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::Unlinked->value)
        ->assertDontSeeHtml('data-unlinked-sender=')
        ->assertSee('Every conversation is on a record.');
});

it('links the sender history to a person created from the group', function (): void {
    $email = conversationsPageMessage($this->ownerMailbox, 'conv-brian', 'brian@airbnb.test');

    $page = livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::Unlinked->value)
        ->mountAction('createSenderPerson', arguments: ['address' => 'brian@airbnb.test', 'name' => 'Brian Chesky'])
        ->assertActionDataSet(['name' => 'Brian Chesky', 'email' => 'brian@airbnb.test'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Created Brian Chesky');

    $person = People::query()->where('name', 'Brian Chesky')->sole();

    expect($email->people()->whereKey($person->id)->exists())->toBeTrue()
        ->and(unlinkedSenderAddresses($page))->toBe([]);
});

it('offers to link the sender to the person who already holds the address instead of creating a second one', function (): void {
    $person = People::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Brian Chesky']);
    $field = CustomField::query()->withoutGlobalScopes()->where('tenant_id', $this->workspace->id)->where('entity_type', 'people')->where('code', 'emails')->firstOrFail();
    $person->saveCustomFieldValue($field, ['Brian@Airbnb.test'], $this->workspace);
    $email = conversationsPageMessage($this->ownerMailbox, 'conv-brian', 'brian@airbnb.test');
    $removed = conversationsPageMessage($this->ownerMailbox, 'conv-brian-old', 'brian@airbnb.test', ['sent_at' => now()->subWeek()]);
    EmailLinkSuppression::factory()->create(['workspace_id' => $this->workspace->id, 'email_id' => $removed->id, 'emailable_type' => $person->getMorphClass(), 'emailable_id' => $person->id, 'user_id' => $this->owner->id]);

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::Unlinked->value)
        ->mountAction('createSenderPerson', arguments: ['address' => 'brian@airbnb.test', 'name' => 'Brian Chesky'])
        ->assertMountedActionModalSee('Brian Chesky already has this address')
        ->assertMountedActionModalSee('Link to Brian Chesky')
        ->assertMountedActionModalSee('One conversation removed from Brian Chesky earlier stays removed.')
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Linked to Brian Chesky');

    expect(People::query()->where('name', 'Brian Chesky')->count())->toBe(1)
        ->and($email->people()->wherePivot('link_source', 'manual')->pluck('people.id')->all())->toBe([$person->id])
        ->and($removed->people()->exists())->toBeFalse()
        ->and(EmailLinkSuppression::query()->where('email_id', $removed->id)->exists())->toBeTrue();
});

it('keeps a removal a teammate made on the viewer\'s copy when linking the sender to an existing person', function (): void {
    $person = People::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Brian Chesky']);
    $field = CustomField::query()->withoutGlobalScopes()->where('tenant_id', $this->workspace->id)->where('entity_type', 'people')->where('code', 'emails')->firstOrFail();
    $person->saveCustomFieldValue($field, ['brian@airbnb.test'], $this->workspace);
    $email = conversationsPageMessage($this->ownerMailbox, 'conv-brian', 'brian@airbnb.test');
    $removed = conversationsPageMessage($this->ownerMailbox, 'conv-brian-old', 'brian@airbnb.test', ['sent_at' => now()->subWeek()]);
    EmailLinkSuppression::factory()->create(['workspace_id' => $this->workspace->id, 'email_id' => $removed->id, 'emailable_type' => $person->getMorphClass(), 'emailable_id' => $person->id, 'user_id' => $this->teammate->id]);

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::Unlinked->value)
        ->mountAction('createSenderPerson', arguments: ['address' => 'brian@airbnb.test', 'name' => 'Brian Chesky'])
        ->assertMountedActionModalSee('One conversation removed from Brian Chesky earlier stays removed.')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($email->people()->pluck('people.id')->all())->toBe([$person->id])
        ->and($removed->people()->exists())->toBeFalse()
        ->and(EmailLinkSuppression::query()->where('email_id', $removed->id)->exists())->toBeTrue();
});

it('locks the person row before it reads the addresses it adds to', function (): void {
    $person = People::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Brian Chesky']);
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    resolve(AddEmailAddressToPerson::class)->execute($this->owner, $person, 'brian@airbnb.test');

    $lock = collect($statements)->search(fn (string $sql): bool => str_starts_with($sql, 'select * from "people"') && str_ends_with($sql, 'for update'));
    $read = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'from "custom_field_values"'));

    expect($lock)->toBeInt()
        ->and($lock)->toBeLessThan($read);
});

it('adds the sender address to an existing person once', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-brian', 'brian@airbnb.test');
    $person = People::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Brian Chesky']);
    $foreign = People::factory()->create(['name' => 'Brian Elsewhere']);
    $link = fn (string $personId): Testable => livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::Unlinked->value)
        ->mountAction('linkSenderToPerson', arguments: ['address' => 'Brian@Airbnb.test'])
        ->setActionData(['person_id' => $personId])
        ->callMountedAction();

    $link($person->id)->assertHasNoActionErrors()->assertNotified('Added Brian@Airbnb.test to Brian Chesky');
    $link($person->id)->assertHasNoActionErrors();
    $link($foreign->id)->assertHasActionErrors(['person_id']);

    expect(unlinkedPersonAddresses($person))->toBe(['brian@airbnb.test'])
        ->and(unlinkedPersonAddresses($foreign))->toBe([]);
});
