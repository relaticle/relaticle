<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Features\OnboardSeed;
use App\Listeners\CreateTeamCustomFields;
use App\Models\Company;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Jobs\SendWelcomeMessage;
use Relaticle\Chat\Models\AiCreditBalance;

mutates(SendWelcomeMessage::class, CreateTeamCustomFields::class);

it('dispatches the welcome job when a personal team is created', function (): void {
    Feature::define(OnboardSeed::class, true);
    Queue::fake([SendWelcomeMessage::class]);

    User::factory()->withPersonalTeam()->create();

    Queue::assertPushed(SendWelcomeMessage::class);
});

it('does not dispatch for a non-personal team', function (): void {
    Feature::define(OnboardSeed::class, true);
    Queue::fake([SendWelcomeMessage::class]);

    $owner = User::factory()->withPersonalTeam()->create();
    Queue::fake([SendWelcomeMessage::class]);
    Team::factory()->create(['user_id' => $owner->getKey(), 'personal_team' => false]);

    Queue::assertNotPushed(SendWelcomeMessage::class);
});

it('writes the welcome conversation synchronously, before the job runs', function (): void {
    Feature::define(OnboardSeed::class, true);
    Queue::fake([SendWelcomeMessage::class]);

    $owner = User::factory()->withPersonalTeam()->create();

    $conversation = DB::table('agent_conversations')
        ->where('team_id', $owner->currentTeam->getKey())
        ->first();

    expect($conversation)->not->toBeNull()
        ->and($conversation->participant_id)->toBe((string) $owner->getKey())
        ->and($conversation->title)->toBe(__('chat-welcome.title'));

    $message = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversation->id)
        ->first();

    expect($message->role)->toBe('assistant')
        ->and($message->content)->toBe(__('chat-welcome.fallback', [
            'name' => explode(' ', trim($owner->name))[0],
        ]))
        ->and(json_decode((string) $message->meta, true))->toMatchArray(['welcome' => true]);
});

it('leaves the welcome copy alone once the user has replied', function (): void {
    Feature::define(OnboardSeed::class, true);

    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->currentTeam;

    $conversationId = DB::table('agent_conversations')->where('team_id', $team->getKey())->value('id');

    // A sentinel distinct from the templated fallback: the fallback text is
    // what compose() also falls back to with no AI provider faked, so leaving
    // $before untouched would make a guard removal and a same-content refine
    // look identical.
    DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->where('role', 'assistant')
        ->update(['content' => 'sentinel before refine']);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'participant_type' => $owner->getMorphClass(),
        'participant_id' => (string) $owner->getKey(),
        'agent' => CrmAssistant::class,
        'role' => 'user',
        'content' => 'already talking',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new SendWelcomeMessage($team))->handle();

    expect(DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->where('role', 'assistant')->value('content'))
        ->toBe('sentinel before refine');
});

it('does not insert a second welcome message when it refines', function (): void {
    Feature::define(OnboardSeed::class, true);

    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->currentTeam;

    $conversationId = DB::table('agent_conversations')->where('team_id', $team->getKey())->value('id');

    // Same sentinel as the sibling test, so a refine that actually runs is
    // provable: the content must move away from the sentinel.
    DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->where('role', 'assistant')
        ->update(['content' => 'sentinel before refine']);

    (new SendWelcomeMessage($team))->handle();

    expect(DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->where('role', 'assistant')->value('content'))
        ->toBe(__('chat-welcome.fallback', ['name' => explode(' ', trim($owner->name))[0]]))
        ->and(DB::table('agent_conversation_messages')
            ->whereRaw("coalesce(meta->>'welcome', '') = 'true'")
            ->count())->toBe(1);
});

it('writes the welcome conversation with the fallback copy when generation fails', function (): void {
    // No AI provider is faked, so the WelcomeComposer prompt throws and the
    // job must fall back to the templated message.
    Feature::define(OnboardSeed::class, true);

    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->currentTeam;

    (new SendWelcomeMessage($team))->handle();

    $conversation = DB::table('agent_conversations')->where('team_id', $team->getKey())->first();
    expect($conversation)->not->toBeNull()
        ->and($conversation->participant_id)->toBe((string) $owner->getKey())
        ->and($conversation->title)->toBe(__('chat-welcome.title'));

    $message = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversation->id)
        ->first();
    expect($message)->not->toBeNull()
        ->and($message->role)->toBe('assistant')
        ->and($message->content)->toContain($owner->name ? explode(' ', $owner->name)[0] : '')
        ->and(json_decode((string) $message->meta, true))->toMatchArray(['welcome' => true]);
});

it('never charges the team credit balance', function (): void {
    Feature::define(OnboardSeed::class, true);

    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->currentTeam;
    $before = AiCreditBalance::query()->where('team_id', $team->getKey())->value('credits_remaining');

    (new SendWelcomeMessage($team))->handle();

    expect(AiCreditBalance::query()->where('team_id', $team->getKey())->value('credits_remaining'))
        ->toBe($before);
});

it('does not write a second conversation when run twice', function (): void {
    Feature::define(OnboardSeed::class, true);

    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->currentTeam;

    (new SendWelcomeMessage($team))->handle();
    (new SendWelcomeMessage($team))->handle();

    expect(DB::table('agent_conversations')->where('team_id', $team->getKey())->count())->toBe(1);
});

/**
 * The other tests all reach the templated fallback, because Http::preventStrayRequests()
 * makes generation throw. That hid a crash in the prompt-building path itself: it queried
 * a column tasks does not have, and the job died before it could fall back. Build the
 * prompt directly so the generation path is exercised on its own.
 */
it('builds the workspace prompt from real seeded records', function (): void {
    $owner = User::factory()->withPersonalTeam()->create(['name' => 'Dana Whitfield']);
    $team = $owner->currentTeam;

    Company::factory()->create([
        'team_id' => $team->getKey(),
        'name' => 'Northwind Traders',
        'creation_source' => CreationSource::SYSTEM,
    ]);
    Task::factory()->create([
        'team_id' => $team->getKey(),
        'title' => 'Follow up with Dylan',
        'creation_source' => CreationSource::SYSTEM,
    ]);

    $job = new SendWelcomeMessage($team);
    $block = (new ReflectionMethod($job, 'workspaceBlock'))->invoke($job, 'Dana');

    expect($block)->toContain('Northwind Traders')
        ->and($block)->toContain('Follow up with Dylan')
        ->and($block)->toContain('Owner first name: Dana');
});

it('greets a user whose name is blank without a dangling comma', function (): void {
    Feature::define(OnboardSeed::class, true);

    $owner = User::factory()->withPersonalTeam()->create(['name' => ' ']);
    $team = $owner->currentTeam;

    (new SendWelcomeMessage($team))->handle();

    $content = DB::table('agent_conversation_messages')
        ->join('agent_conversations as c', 'c.id', '=', 'agent_conversation_messages.conversation_id')
        ->where('c.team_id', $team->getKey())
        ->value('agent_conversation_messages.content');

    expect($content)->not->toContain('Hi ,')
        ->and($content)->toContain(__('chat-welcome.default_name'));
});

it('strips em dashes from a generated welcome', function (): void {
    $owner = User::factory()->withPersonalTeam()->create(['name' => 'Priya Raman']);

    $job = new SendWelcomeMessage($owner->currentTeam);
    $clean = (new ReflectionMethod($job, 'sanitize'))
        ->invoke($job, "Hi Priya, welcome\u{2014}here is what is next.");

    expect($clean)->not->toContain("\u{2014}")
        ->and($clean)->toContain('welcome, here is what is next.');
});
