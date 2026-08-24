<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Enums\TeamRole;
use App\Models\People;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Onboarding\ActivationSteps;
use App\Services\WorkspaceActivationFacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Spatie\Onboard\OnboardingStep;

mutates(ActivationSteps::class, WorkspaceActivationFacts::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->currentTeam;
});

/**
 * spatie/laravel-onboard reuses the same OnboardingStep instance (from the
 * OnboardingSteps singleton) for every model, and OnboardingStep::complete()
 * memoizes its result via Laravel's once() helper — keyed on the step
 * object, not the model. Within a single test that mutates state between
 * assertions, the memoized boolean goes stale unless both caches are
 * cleared before each read: the package's once() cache, and our own
 * per-team WorkspaceActivationFacts cache.
 */
function stepByKey(Team $team, string $key): OnboardingStep
{
    Once::flush();
    resolve(WorkspaceActivationFacts::class)->forget($team);

    return $team->onboarding()->steps()
        ->first(fn (OnboardingStep $step): bool => $step->attribute('key') === $key);
}

it('registers exactly four steps for a team', function (): void {
    expect($this->team->onboarding()->steps())->toHaveCount(4);
});

it('completes first_record only for non-system records', function (): void {
    expect(stepByKey($this->team, 'first_record')->complete())->toBeFalse();

    People::factory()->create([
        'team_id' => $this->team->getKey(),
        'creation_source' => CreationSource::SYSTEM,
    ]);
    expect(stepByKey($this->team, 'first_record')->complete())->toBeFalse();

    People::factory()->create([
        'team_id' => $this->team->getKey(),
        'creation_source' => CreationSource::WEB,
    ]);
    expect(stepByKey($this->team->refresh(), 'first_record')->complete())->toBeTrue();
});

it('completes import when an imported record exists', function (): void {
    People::factory()->create([
        'team_id' => $this->team->getKey(),
        'creation_source' => CreationSource::IMPORT,
    ]);

    expect(stepByKey($this->team, 'import')->complete())->toBeTrue();
});

it('completes invite for a pending invitation', function (): void {
    expect(stepByKey($this->team, 'invite')->complete())->toBeFalse();

    TeamInvitation::query()->create([
        'team_id' => $this->team->getKey(),
        'email' => 'teammate@example.com',
        'role' => TeamRole::Editor->value,
    ]);

    expect(stepByKey($this->team, 'invite')->complete())->toBeTrue();
});

it('completes ask_rela only when a user-role message exists, not for the seeded welcome', function (): void {
    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $this->owner->getMorphClass(),
        'participant_id' => (string) $this->owner->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => 'Welcome',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $baseMessage = [
        'conversation_id' => $conversationId,
        'participant_type' => $this->owner->getMorphClass(),
        'participant_id' => (string) $this->owner->getKey(),
        'agent' => 'crm',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'role' => 'assistant',
        'content' => 'Welcome!',
        ...$baseMessage,
    ]);

    expect(stepByKey($this->team, 'ask_rela')->complete())->toBeFalse();

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'role' => 'user',
        'content' => 'hi',
        ...$baseMessage,
    ]);

    expect(stepByKey($this->team->refresh(), 'ask_rela')->complete())->toBeTrue();
});

it('scopes facts to the team', function (): void {
    $other = Team::factory()->create();
    People::factory()->create([
        'team_id' => $other->getKey(),
        'creation_source' => CreationSource::WEB,
    ]);

    expect(stepByKey($this->team, 'first_record')->complete())->toBeFalse();
});

it('answers all creation-source facts in one query per team', function (): void {
    DB::enableQueryLog();

    $facts = resolve(WorkspaceActivationFacts::class);
    $facts->hasOwnRecord($this->team);
    $facts->hasImportedRecord($this->team);
    $facts->hasSampleData($this->team);

    $sourceQueries = collect(DB::getQueryLog())
        ->filter(fn (array $entry): bool => str_contains((string) $entry['query'], 'creation_source'));

    expect($sourceQueries)->toHaveCount(1);
});
