<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Http\Controllers\ChatController;
use Tests\Helpers\ChatDocument;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

mutates(ChatController::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function seedSearchableMessage(User $participant, string $conversationId, string $id, string $content, array $overrides = []): void
{
    DB::table('agent_conversation_messages')->insert([
        'id' => $id,
        'conversation_id' => $conversationId,
        'participant_type' => $participant->getMorphClass(),
        'participant_id' => (string) $participant->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'role' => 'user',
        'content' => $content,
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);
}

function seedSearchableConversation(User $participant, string $conversationId, ?string $teamId = null): void
{
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $participant->getMorphClass(),
        'participant_id' => (string) $participant->getKey(),
        'team_id' => $teamId ?? (string) $participant->currentTeam->getKey(),
        'title' => 'Searchable',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('finds a message by substring and returns its id with a snippet', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    seedSearchableConversation($user, 'conv-find');
    seedSearchableMessage($user, 'conv-find', 'm-1', 'Remind me about the Northwind renewal next quarter');
    seedSearchableMessage($user, 'conv-find', 'm-2', 'Completely unrelated chatter');

    actingAs($user);

    $response = getJson(route('chat.conversations.search', ['conversationId' => 'conv-find', 'q' => 'northwind']))
        ->assertOk();

    expect($response->json('matches'))->toHaveCount(1)
        ->and($response->json('matches.0.message_id'))->toBe('m-1')
        ->and($response->json('matches.0.snippet'))->toContain('Northwind');
});

it('strips markdown syntax from snippets', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    seedSearchableConversation($user, 'conv-md');
    seedSearchableMessage(
        $user,
        'conv-md',
        'm-md',
        "There's 1 task with the status **Done**, [Send proposal to Tim](/r/task/01abc) is ready.",
        ['role' => 'assistant'],
    );

    actingAs($user);

    $response = getJson(route('chat.conversations.search', ['conversationId' => 'conv-md', 'q' => 'proposal']))
        ->assertOk();

    expect($response->json('matches.0.snippet'))
        ->toContain('Done')
        ->toContain('Send proposal to Tim')
        ->not->toContain('**')
        ->not->toContain('](/r/');
});

it('matches assistant messages too', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    seedSearchableConversation($user, 'conv-assistant');
    seedSearchableMessage($user, 'conv-assistant', 'm-1', "Here is the pipeline:\n\n- **Northwind** renewal", ['role' => 'assistant']);

    actingAs($user);

    $response = getJson(route('chat.conversations.search', ['conversationId' => 'conv-assistant', 'q' => 'northwind']))
        ->assertOk();

    expect($response->json('matches.0.message_id'))->toBe('m-1');
});

it('excludes superseded messages', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    seedSearchableConversation($user, 'conv-superseded');
    seedSearchableMessage($user, 'conv-superseded', 'm-1', 'Northwind renewal, first attempt', ['superseded_at' => now()]);
    seedSearchableMessage($user, 'conv-superseded', 'm-2', 'Northwind renewal, second attempt');

    actingAs($user);

    $response = getJson(route('chat.conversations.search', ['conversationId' => 'conv-superseded', 'q' => 'northwind']))
        ->assertOk();

    expect($response->json('matches'))->toHaveCount(1)
        ->and($response->json('matches.0.message_id'))->toBe('m-2');
});

it('excludes approval bookkeeping messages the transcript never renders', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    seedSearchableConversation($user, 'conv-approval');
    seedSearchableMessage($user, 'conv-approval', 'm-1', '[approval] approved the Northwind update');

    actingAs($user);

    $response = getJson(route('chat.conversations.search', ['conversationId' => 'conv-approval', 'q' => 'northwind']))
        ->assertOk();

    expect($response->json('matches'))->toBe([]);
});

it('returns 404 for a conversation owned by another participant', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $intruder = User::factory()->withPersonalTeam()->create();

    seedSearchableConversation($owner, 'conv-owned');
    seedSearchableMessage($owner, 'conv-owned', 'm-1', 'Northwind renewal');

    actingAs($intruder);

    getJson(route('chat.conversations.search', ['conversationId' => 'conv-owned', 'q' => 'northwind']))
        ->assertNotFound();
});

it('returns 404 for a teammates conversation inside the very same team', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->currentTeam;

    $teammate = User::factory()->create();
    $teammate->teams()->attach($team, ['role' => 'admin']);
    $teammate->forceFill(['current_team_id' => $team->getKey()])->save();

    seedSearchableConversation($owner, 'conv-teammate', (string) $team->getKey());
    seedSearchableMessage($owner, 'conv-teammate', 'm-1', 'Northwind renewal');

    actingAs($teammate);

    getJson(route('chat.conversations.search', ['conversationId' => 'conv-teammate', 'q' => 'northwind']))
        ->assertNotFound();
});

it('returns 404 for the participants own conversation in a team they are not currently in', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $otherTeam = Team::factory()->create(['user_id' => $user->getKey()]);
    $user->teams()->attach($otherTeam, ['role' => 'admin']);

    seedSearchableConversation($user, 'conv-other-team', (string) $otherTeam->getKey());
    seedSearchableMessage($user, 'conv-other-team', 'm-1', 'Northwind renewal');

    actingAs($user);

    getJson(route('chat.conversations.search', ['conversationId' => 'conv-other-team', 'q' => 'northwind']))
        ->assertNotFound();

    $user->forceFill(['current_team_id' => $otherTeam->getKey()])->save();

    getJson(route('chat.conversations.search', ['conversationId' => 'conv-other-team', 'q' => 'northwind']))
        ->assertOk()
        ->assertJsonPath('matches.0.message_id', 'm-1');
});

it('never returns another conversations messages', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    seedSearchableConversation($user, 'conv-a');
    seedSearchableConversation($user, 'conv-b');
    seedSearchableMessage($user, 'conv-a', 'm-a', 'Northwind renewal in A');
    seedSearchableMessage($user, 'conv-b', 'm-b', 'Northwind renewal in B');

    actingAs($user);

    $response = getJson(route('chat.conversations.search', ['conversationId' => 'conv-a', 'q' => 'northwind']))
        ->assertOk();

    expect($response->json('matches'))->toHaveCount(1)
        ->and($response->json('matches.0.message_id'))->toBe('m-a');
});

it('treats underscore and percent in the query as literals', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    seedSearchableConversation($user, 'conv-wildcards');
    seedSearchableMessage($user, 'conv-wildcards', 'm-literal', 'field foo_bar is stale');
    seedSearchableMessage($user, 'conv-wildcards', 'm-wildcard', 'field fooxbar is stale');

    actingAs($user);

    $response = getJson(route('chat.conversations.search', ['conversationId' => 'conv-wildcards', 'q' => 'o_b']))
        ->assertOk();

    expect($response->json('matches'))->toHaveCount(1)
        ->and($response->json('matches.0.message_id'))->toBe('m-literal');
});

it('caps results at twenty, newest first', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    seedSearchableConversation($user, 'conv-cap');

    foreach (range(1, 25) as $i) {
        seedSearchableMessage($user, 'conv-cap', sprintf('m-%03d', $i), "Northwind touchpoint {$i}");
    }

    actingAs($user);

    $response = getJson(route('chat.conversations.search', ['conversationId' => 'conv-cap', 'q' => 'northwind']))
        ->assertOk();

    expect($response->json('matches'))->toHaveCount(20)
        ->and($response->json('matches.0.message_id'))->toBe('m-025');
});

it('returns an empty match list when nothing matches', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    seedSearchableConversation($user, 'conv-empty');
    seedSearchableMessage($user, 'conv-empty', 'm-1', 'Nothing of interest here');

    actingAs($user);

    getJson(route('chat.conversations.search', ['conversationId' => 'conv-empty', 'q' => 'northwind']))
        ->assertOk()
        ->assertExactJson(['matches' => []]);
});

it('rejects a query shorter than two characters', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    seedSearchableConversation($user, 'conv-short');

    actingAs($user);

    getJson(route('chat.conversations.search', ['conversationId' => 'conv-short', 'q' => 'a']))
        ->assertUnprocessable();
});
