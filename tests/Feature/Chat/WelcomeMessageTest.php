<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Listeners\CreateTeamCustomFields;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
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

it('writes the welcome conversation with the fallback copy when generation fails', function (): void {
    // No AI provider is faked, so the WelcomeComposer prompt throws and the
    // job must fall back to the templated message.
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
    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->currentTeam;
    $before = AiCreditBalance::query()->where('team_id', $team->getKey())->value('credits_remaining');

    (new SendWelcomeMessage($team))->handle();

    expect(AiCreditBalance::query()->where('team_id', $team->getKey())->value('credits_remaining'))
        ->toBe($before);
});

it('does not write a second conversation when run twice', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->currentTeam;

    (new SendWelcomeMessage($team))->handle();
    (new SendWelcomeMessage($team))->handle();

    expect(DB::table('agent_conversations')->where('team_id', $team->getKey())->count())->toBe(1);
});
