<?php

declare(strict_types=1);

use App\Jobs\Email\SyncSubscriberJob;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\UserMessage;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Enums\MessageOrigin;
use Relaticle\Chat\Models\AgentConversationMessage;
use Relaticle\Chat\Storage\SupersededAwareConversationStore;
use Relaticle\Chat\Support\FirstChatUsageTagger;

mutates(FirstChatUsageTagger::class, SupersededAwareConversationStore::class, AgentConversationMessage::class);

function storeChatUserMessage(User $user, string $conversationId, string $text): string
{
    return resolve(SupersededAwareConversationStore::class)
        ->storeUserMessage($conversationId, $user->getMorphClass(), (string) $user->getKey(), CrmAssistant::class, new UserMessage($text));
}

function seedChatConversation(User $user): string
{
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $user->currentWorkspace->getKey(),
        'title' => 'T',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $conversationId;
}

beforeEach(function (): void {
    Queue::fake([SyncSubscriberJob::class]);
    config()->set('mailcoach-sdk.enabled_subscribers_sync', true);
});

test('sending the first chat message dispatches a profile sync', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $conversationId = seedChatConversation($user);

    storeChatUserMessage($user, $conversationId, 'hello');

    Queue::assertPushed(SyncSubscriberJob::class, fn (SyncSubscriberJob $job): bool => invade($job)->userId === (string) $user->id);
});

test('a second chat message does not dispatch a sync again', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $conversationId = seedChatConversation($user);

    storeChatUserMessage($user, $conversationId, 'first');

    Queue::fake([SyncSubscriberJob::class]);

    storeChatUserMessage($user, $conversationId, 'second');

    Queue::assertNotPushed(SyncSubscriberJob::class);
});

test('a first message in a second conversation does not dispatch a sync again', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    storeChatUserMessage($user, seedChatConversation($user), 'first');

    Queue::fake([SyncSubscriberJob::class]);

    storeChatUserMessage($user, seedChatConversation($user), 'second, different conversation');

    Queue::assertNotPushed(SyncSubscriberJob::class);
});

test('sending a chat message when subscriber sync is disabled does not dispatch', function (): void {
    config()->set('mailcoach-sdk.enabled_subscribers_sync', false);

    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    storeChatUserMessage($user, seedChatConversation($user), 'hello');

    Queue::assertNotPushed(SyncSubscriberJob::class);
});

test('an assistant reply is not counted as the user having used chat', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $conversationId = seedChatConversation($user);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'agent' => 'crm-assistant',
        'participant_type' => $user->getMorphClass(),
        'participant_id' => (string) $user->getKey(),
        'role' => 'assistant',
        'content' => 'hi there',
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(AgentConversationMessage::query()->sentBy($user)->exists())->toBeFalse();

    storeChatUserMessage($user, $conversationId, 'hello');

    Queue::assertPushed(SyncSubscriberJob::class, fn (SyncSubscriberJob $job): bool => invade($job)->userId === (string) $user->id);
});

test('a greeting row never dispatches a sync, and the first typed message after it still does', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $conversationId = seedChatConversation($user);

    Context::scope(
        fn (): string => storeChatUserMessage($user, $conversationId, MessageOrigin::Greeting->opener()),
        hidden: [MessageOrigin::CONTEXT_KEY => MessageOrigin::Greeting->value],
    );

    Queue::assertNotPushed(SyncSubscriberJob::class);

    storeChatUserMessage($user, $conversationId, 'hello');

    Queue::assertPushed(SyncSubscriberJob::class, 1);
});
