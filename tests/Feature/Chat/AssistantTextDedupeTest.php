<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Storage\SupersededAwareConversationStore;

mutates(SupersededAwareConversationStore::class);

function storeAssistantTextFixture(User $user, string $text): string
{
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => 'T',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $agent = resolve(CrmAssistant::class);
    $provider = Ai::textProviderFor($agent);

    $prompt = new AgentPrompt($agent, 'x', [], $provider, $provider->defaultTextModel());
    $response = new AgentResponse('inv-'.Str::random(8), $text, new Usage, new Meta);

    resolve(SupersededAwareConversationStore::class)
        ->storeAssistantMessage($conversationId, (string) $user->getKey(), $prompt, $response);

    return $conversationId;
}

it('collapses a fully-repeated assistant text down to a single copy when persisting', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $conversationId = storeAssistantTextFixture($user, 'Review the proposal below.Review the proposal below.');

    $content = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->where('role', 'assistant')
        ->value('content');

    expect($content)->toBe('Review the proposal below.');
});

it('persists non-repeated assistant text unchanged', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $conversationId = storeAssistantTextFixture($user, 'Created Alpha and Beta.');

    $content = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->where('role', 'assistant')
        ->value('content');

    expect($content)->toBe('Created Alpha and Beta.');
});
