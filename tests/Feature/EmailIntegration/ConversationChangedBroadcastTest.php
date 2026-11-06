<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\CustomField;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Relaticle\EmailIntegration\Actions\PurgeStoredMailboxData;
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;
use Relaticle\EmailIntegration\Actions\StoreEmailAction;
use Relaticle\EmailIntegration\Actions\UpdateEmailSharingAction;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Events\ConversationChanged;
use Relaticle\EmailIntegration\Jobs\MergeConversations;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\WorkspaceEmailBlocklist;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

mutates(ConversationChanged::class, RefreshConversationSummary::class, UpdateEmailSharingAction::class, ConversationsQuery::class, PurgeStoredMailboxData::class, MergeConversations::class);

beforeEach(function (): void {
    $this->alice = User::factory()->withWorkspace()->create();
    $this->workspace = $this->alice->currentWorkspace;
    $this->bob = conversationChangedMember($this->workspace->id);
    $this->carol = conversationChangedMember($this->workspace->id);
    $this->dan = User::factory()->withWorkspace()->create();
    $this->workspace->users()->attach($this->dan, ['role' => 'member']);
    $this->eve = User::factory()->withWorkspace()->create();

    actingAs($this->alice);
    Filament::setTenant($this->workspace);

    $this->mailbox = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->alice->id,
        'email_address' => 'alice@acme.test',
        'status' => 'active',
        'sharing_tier' => EmailPrivacyTier::METADATA_ONLY,
    ]));

    $company = Company::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Airbnb']);
    $domains = CustomField::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->id)
        ->where('entity_type', 'company')
        ->where('code', 'domains')
        ->firstOrFail();
    $company->saveCustomFieldValue($domains, 'https://airbnb.com', $this->workspace);

    config(['broadcasting.default' => 'log']);
    Event::fake([ConversationChanged::class]);
});

function conversationChangedMember(string $workspaceId): User
{
    $member = User::factory()->create(['current_workspace_id' => $workspaceId]);
    $member->workspaces()->attach($workspaceId, ['role' => 'member']);

    return $member;
}

function conversationChangedStore(ConnectedAccount $mailbox, string $from = 'brian@airbnb.com'): Email
{
    return resolve(StoreEmailAction::class)->execute($mailbox, new FetchedEmailData(
        providerMessageId: 'msg-pricing',
        rfcMessageId: '<pricing@airbnb.com>',
        threadId: 'thread-pricing',
        inReplyTo: null,
        subject: 'Pricing for the Q3 rollout',
        snippet: 'Can you send the numbers',
        sentAt: now(),
        direction: EmailDirection::INBOUND,
        folder: EmailFolder::Inbox,
        hasAttachments: false,
        isRead: false,
        bodyText: 'Can you send the numbers',
        bodyHtml: '<p>Can you send the numbers</p>',
        participants: [
            ['email_address' => $from, 'name' => 'Brian', 'role' => 'from'],
            ['email_address' => $mailbox->email_address, 'name' => 'Alice', 'role' => 'to'],
        ],
        attachments: [],
    ));
}

/**
 * @return list<string>
 */
function conversationChangedAudience(): array
{
    $events = Event::dispatched(ConversationChanged::class);

    expect($events)->not->toBeEmpty();

    return collect($events->last()[0]->broadcastOn())
        ->map(fn (PrivateChannel $channel): string => $channel->name)
        ->sort()
        ->values()
        ->all();
}

/**
 * @return list<string>
 */
function conversationChangedChannels(User ...$users): array
{
    return collect($users)
        ->map(fn (User $user): string => "private-App.Models.User.{$user->id}")
        ->sort()
        ->values()
        ->all();
}

it('signals the owner and a teammate who may see the linked conversation', function (): void {
    conversationChangedStore($this->mailbox);

    expect(conversationChangedAudience())->toBe(conversationChangedChannels($this->alice, $this->bob, $this->carol));
});

it('does not signal a teammate when the mailbox is private', function (): void {
    $this->mailbox->update(['sharing_tier' => EmailPrivacyTier::PRIVATE]);

    conversationChangedStore($this->mailbox);

    expect(conversationChangedAudience())->toBe(conversationChangedChannels($this->alice));
});

it('does not signal a teammate when the conversation is linked to no record', function (): void {
    $email = conversationChangedStore($this->mailbox, 'brian@unknown-vendor.test');

    expect($email->fresh()->companies()->exists())->toBeFalse()
        ->and(conversationChangedAudience())->toBe(conversationChangedChannels($this->alice));
});

it('does not signal a teammate blocked by a workspace visibility rule', function (): void {
    WorkspaceEmailBlocklist::factory()->protected()->email('brian@airbnb.com')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->alice->id,
    ]);

    conversationChangedStore($this->mailbox);

    expect(conversationChangedAudience())->toBe(conversationChangedChannels($this->alice));
});

it('does not signal a member whose current workspace is another one', function (): void {
    conversationChangedStore($this->mailbox);

    expect(conversationChangedAudience())
        ->not->toContain("private-App.Models.User.{$this->dan->id}")
        ->not->toContain("private-App.Models.User.{$this->eve->id}");
});

it('carries only the workspace id and the conversation key', function (): void {
    $email = conversationChangedStore($this->mailbox);

    $event = Event::dispatched(ConversationChanged::class)->last()[0];

    expect(array_keys($event->broadcastWith()))->toBe(['workspace_id', 'conversation_key'])
        ->and($event->broadcastWith())->toBe(['workspace_id' => $this->workspace->id, 'conversation_key' => $email->conversation_key])
        ->and($event->broadcastAs())->toBe('email.conversation.changed');
});

it('sends nothing when the store rolls back', function (): void {
    expect(fn (): mixed => DB::transaction(function (): never {
        $email = conversationChangedStore($this->mailbox);
        resolve(RefreshConversationSummary::class)->execute($this->workspace->id, (string) $email->conversation_key);

        throw new RuntimeException('Store failed after the summary refresh.');
    }))->toThrow(RuntimeException::class);

    Event::assertNotDispatched(ConversationChanged::class);
});

it('signals a teammate after the owner shares the email', function (): void {
    $this->mailbox->update(['sharing_tier' => EmailPrivacyTier::PRIVATE]);
    $email = conversationChangedStore($this->mailbox);

    expect(conversationChangedAudience())->toBe(conversationChangedChannels($this->alice));

    Event::fake([ConversationChanged::class]);

    resolve(UpdateEmailSharingAction::class)->execute($email->fresh(), $this->alice, EmailPrivacyTier::PRIVATE, [
        ['shared_with' => $this->carol->id, 'tier' => EmailPrivacyTier::SUBJECT],
    ]);

    expect(conversationChangedAudience())->toBe(conversationChangedChannels($this->alice, $this->carol));
});

it('refuses another member the user channel', function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    resolve(BroadcastManager::class)->forgetDrivers();
    require base_path('routes/channels.php');

    actingAs($this->bob);

    postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => "private-App.Models.User.{$this->alice->id}",
    ])->assertForbidden();

    postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => "private-App.Models.User.{$this->bob->id}",
    ])->assertOk();
});

/**
 * @return list<string>
 */
function conversationChangedAudienceOf(string $conversationKey): array
{
    $event = Event::dispatched(ConversationChanged::class, fn (ConversationChanged $event): bool => $event->conversationKey === $conversationKey)->last()[0];

    return collect($event->broadcastOn())->map(fn (PrivateChannel $channel): string => $channel->name)->sort()->values()->all();
}

it('signals a teammate whose share is revoked', function (): void {
    $this->mailbox->update(['sharing_tier' => EmailPrivacyTier::PRIVATE]);
    $email = conversationChangedStore($this->mailbox);
    resolve(UpdateEmailSharingAction::class)->execute($email->fresh(), $this->alice, EmailPrivacyTier::PRIVATE, [
        ['shared_with' => $this->carol->id, 'tier' => EmailPrivacyTier::SUBJECT],
    ]);

    Event::fake([ConversationChanged::class]);

    resolve(UpdateEmailSharingAction::class)->execute($email->fresh(), $this->alice, EmailPrivacyTier::PRIVATE, []);

    expect(conversationChangedAudience())->toBe(conversationChangedChannels($this->alice, $this->carol));
});

it('signals the members who saw a conversation whose last copy is purged', function (): void {
    $carolMailbox = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->carol->id,
        'email_address' => 'carol@acme.test',
        'status' => 'active',
        'sharing_tier' => EmailPrivacyTier::METADATA_ONLY,
    ]));
    $email = conversationChangedStore($carolMailbox);

    Event::fake([ConversationChanged::class]);

    $this->carol->delete();

    expect(Email::query()->withoutGlobalScopes()->whereKey($email->id)->exists())->toBeFalse()
        ->and(conversationChangedAudienceOf((string) $email->conversation_key))->toBe(conversationChangedChannels($this->alice, $this->bob));
});

it('signals the audience of a conversation absorbed by a merge', function (): void {
    $message = fn (string $key, string $rfc, ?string $inReplyTo, CarbonImmutable $sentAt): Email => Email::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->alice->id,
        'connected_account_id' => $this->mailbox->id,
        'conversation_key' => $key,
        'rfc_message_id' => $rfc,
        'in_reply_to' => $inReplyTo,
        'direction' => EmailDirection::INBOUND,
        'status' => EmailStatus::SYNCED,
        'sent_at' => $sentAt,
    ]);
    $parent = $message('conv-parent', '<parent@airbnb.com>', null, now()->subDay());
    $reply = $message('conv-reply', '<reply@airbnb.com>', '<parent@airbnb.com>', now());
    Company::factory()->create(['workspace_id' => $this->workspace->id])->emails()->attach($parent->id);

    Event::fake([ConversationChanged::class]);

    dispatch_sync(new MergeConversations($this->workspace->id, $reply->id));

    expect($reply->fresh()->conversation_key)->toBe('conv-parent')
        ->and(conversationChangedAudienceOf('conv-reply'))->toBe(conversationChangedChannels($this->alice));
});

it('reads no audience before a purge when no broadcaster is configured', function (): void {
    config(['broadcasting.default' => 'null']);
    $carolMailbox = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->carol->id,
        'email_address' => 'carol@acme.test',
        'status' => 'active',
    ]));
    conversationChangedStore($carolMailbox);
    $audienceReads = 0;
    DB::listen(function (QueryExecuted $query) use (&$audienceReads): void {
        if (str_starts_with($query->sql, 'select * from "users"') && str_contains($query->sql, '"current_workspace_id" = ?')) {
            $audienceReads++;
        }
    });

    $this->carol->delete();

    expect($audienceReads)->toBe(0);
});
