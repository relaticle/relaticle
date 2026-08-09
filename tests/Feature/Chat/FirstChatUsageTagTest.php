<?php

declare(strict_types=1);

use App\Enums\SubscriberTagEnum;
use App\Jobs\Email\ModifySubscriberTagsJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Prompts\AgentPrompt;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Storage\SupersededAwareConversationStore;
use Relaticle\Chat\Support\FirstChatUsageTagger;

mutates(FirstChatUsageTagger::class, SupersededAwareConversationStore::class);

function storeChatUserMessage(User $user, string $conversationId, string $text): string
{
    $agent = resolve(CrmAssistant::class);
    $provider = Ai::textProviderFor($agent);

    $prompt = new AgentPrompt($agent, $text, [], $provider, $provider->defaultTextModel());

    return resolve(SupersededAwareConversationStore::class)
        ->storeUserMessage($conversationId, $user->getMorphClass(), (string) $user->getKey(), $prompt);
}

function seedChatConversation(User $user): string
{
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => 'T',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $conversationId;
}

beforeEach(function (): void {
    Queue::fake([ModifySubscriberTagsJob::class]);
    config()->set('mailcoach-sdk.enabled_subscribers_sync', true);
});

test('sending the first chat message dispatches the has-ai-usage tag job', function (): void {
    $user = User::factory()->withPersonalTeam()->create([
        'mailcoach_subscriber_uuid' => 'sub-uuid-1',
    ]);
    $this->actingAs($user);

    $conversationId = seedChatConversation($user);

    storeChatUserMessage($user, $conversationId, 'hello');

    Queue::assertPushed(ModifySubscriberTagsJob::class, function (ModifySubscriberTagsJob $job): bool {
        return invade($job)->subscriberUuid === 'sub-uuid-1'
            && invade($job)->tags === [SubscriberTagEnum::HasAiUsage->value];
    });
});

test('a second chat message does not dispatch the tag job again', function (): void {
    $user = User::factory()->withPersonalTeam()->create([
        'mailcoach_subscriber_uuid' => 'sub-uuid-1',
    ]);
    $this->actingAs($user);

    $conversationId = seedChatConversation($user);

    storeChatUserMessage($user, $conversationId, 'first');

    Queue::fake([ModifySubscriberTagsJob::class]);

    storeChatUserMessage($user, $conversationId, 'second');

    Queue::assertNotPushed(ModifySubscriberTagsJob::class);
});

test('a first message in a second conversation does not dispatch the tag job again', function (): void {
    $user = User::factory()->withPersonalTeam()->create([
        'mailcoach_subscriber_uuid' => 'sub-uuid-1',
    ]);
    $this->actingAs($user);

    storeChatUserMessage($user, seedChatConversation($user), 'first');

    Queue::fake([ModifySubscriberTagsJob::class]);

    storeChatUserMessage($user, seedChatConversation($user), 'second, different conversation');

    Queue::assertNotPushed(ModifySubscriberTagsJob::class);
});

test('a user without a mailcoach subscriber uuid does not dispatch the tag job', function (): void {
    $user = User::factory()->withPersonalTeam()->create([
        'mailcoach_subscriber_uuid' => null,
    ]);
    $this->actingAs($user);

    storeChatUserMessage($user, seedChatConversation($user), 'hello');

    Queue::assertNotPushed(ModifySubscriberTagsJob::class);
});

test('sending a chat message when subscriber sync is disabled does not dispatch the tag job', function (): void {
    config()->set('mailcoach-sdk.enabled_subscribers_sync', false);

    $user = User::factory()->withPersonalTeam()->create([
        'mailcoach_subscriber_uuid' => 'sub-uuid-1',
    ]);
    $this->actingAs($user);

    storeChatUserMessage($user, seedChatConversation($user), 'hello');

    Queue::assertNotPushed(ModifySubscriberTagsJob::class);
});
