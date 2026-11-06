<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\CustomField;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;
use Relaticle\EmailIntegration\Actions\StoreEmailAction;
use Relaticle\EmailIntegration\Actions\UpdateEmailSharingAction;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Events\ConversationChanged;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\WorkspaceEmailBlocklist;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

mutates(ConversationChanged::class, RefreshConversationSummary::class, UpdateEmailSharingAction::class);

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
