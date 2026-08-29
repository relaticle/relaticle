<?php

declare(strict_types=1);

use App\Enums\ActivationStep;
use App\Enums\CreationSource;
use App\Enums\TeamRole;
use App\Models\People;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Onboarding\ActivationSteps;
use App\Services\WorkspaceActivationFacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Onboard\OnboardingStep;

mutates(ActivationSteps::class, WorkspaceActivationFacts::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->currentTeam;
});

/**
 * Each read re-resolves the registry (fresh step objects, so the package's
 * per-object once() memoization cannot go stale) and drops our own per-team
 * fact cache, which is request-scoped by design and would otherwise survive
 * a mid-test insert.
 */
function stepByKey(Team $team, string $key): OnboardingStep
{
    resolve(WorkspaceActivationFacts::class)->forget($team);

    return $team->onboarding()->steps()
        ->first(fn (OnboardingStep $step): bool => $step->attribute('key') === ActivationStep::from($key));
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

it('completes ask_rela only when a user-role message exists, not for an assistant reply alone', function (): void {
    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $this->owner->getMorphClass(),
        'participant_id' => (string) $this->owner->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => 'Pipeline check',
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
        'content' => 'Here is what I found.',
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

/**
 * Regression guard for the reason AppServiceProvider rebinds OnboardingSteps
 * away from the package's singleton: shared step objects memoize the first
 * team's answer through once() and hand it to every later team in the process.
 * Evaluating a workspace with no records first must not make a workspace that
 * has records read as incomplete.
 */
it('does not leak one workspace\'s step state onto another', function (): void {
    $otherOwner = User::factory()->withPersonalTeam()->create();
    $otherTeam = $otherOwner->currentTeam;

    People::factory()->create([
        'team_id' => $otherTeam->getKey(),
        'creation_source' => CreationSource::WEB,
    ]);

    expect($this->team->onboarding()->steps()
        ->first(fn (OnboardingStep $step): bool => $step->attribute('key') === ActivationStep::FirstRecord)
        ->complete())->toBeFalse();

    expect($otherTeam->onboarding()->steps()
        ->first(fn (OnboardingStep $step): bool => $step->attribute('key') === ActivationStep::FirstRecord)
        ->complete())->toBeTrue();
});

/**
 * Registration order is display order, and it is ordered by measured value:
 * a day-0 record and a day-0 chat each predict roughly a 2.5x return rate,
 * while import reaches 0.5% of workspaces and invite 0.3%. Pinning the order
 * here means a future edit that drops import or invite back to the top has to
 * argue with the data rather than slip through as a diff nobody re-measures.
 */
it('orders the steps by measured value, not by build order', function (): void {
    $keys = $this->team->onboarding()->steps()
        ->map(fn (OnboardingStep $step): string => $step->attribute('key')->value)
        ->values()
        ->all();

    expect($keys)->toBe([
        ActivationStep::FirstRecord->value,
        ActivationStep::AskRela->value,
        ActivationStep::Import->value,
        ActivationStep::Invite->value,
    ]);
});
