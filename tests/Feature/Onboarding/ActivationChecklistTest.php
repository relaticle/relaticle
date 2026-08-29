<?php

declare(strict_types=1);

use App\Actions\Onboarding\DismissActivationChecklist;
use App\Enums\CreationSource;
use App\Enums\TeamRole;
use App\Filament\Pages\ChatConversation;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\PeopleResource;
use App\Livewire\App\Onboarding\ActivationChecklist;
use App\Models\People;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\WorkspaceActivationFacts;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

mutates(ActivationChecklist::class, DismissActivationChecklist::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->currentTeam;

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->team);
});

/**
 * The click handler the ask_rela row carries: it seeds the dashboard composer
 * instead of navigating, because an id-less chat URL is not a destination.
 */
function composePromptUrl(string $key = 'prompt_empty'): string
{
    return ChatConversation::getUrl([
        'prompt' => __("filament/pages/dashboard.activation.steps.ask_rela.{$key}"),
    ]);
}

function stepState(string $key, bool $complete): string
{
    return sprintf('data-step="%s" data-complete="%s"', $key, $complete ? 'true' : 'false');
}

it('starts every step incomplete in a fresh workspace', function (): void {
    livewire(ActivationChecklist::class)
        ->assertSeeHtml(stepState('first_record', false))
        ->assertSeeHtml(stepState('import', false))
        ->assertSeeHtml(stepState('invite', false))
        ->assertSeeHtml(stepState('ask_rela', false))
        ->assertSee('0/4 steps completed');
});

it('completes the first-record step once the workspace holds a record the team made', function (): void {
    People::factory()->create([
        'team_id' => $this->team->getKey(),
        'creator_id' => $this->owner->getKey(),
        'creation_source' => CreationSource::WEB,
    ]);

    livewire(ActivationChecklist::class)
        ->assertSeeHtml(stepState('first_record', true))
        ->assertSee('1/4 steps completed');
});

it('leaves the first-record step incomplete while only seeded demo records exist', function (): void {
    People::factory()->create([
        'team_id' => $this->team->getKey(),
        'creation_source' => CreationSource::SYSTEM,
    ]);

    livewire(ActivationChecklist::class)
        ->assertSeeHtml(stepState('first_record', false))
        ->assertSee('0/4 steps completed');
});

it('completes the import step for an imported record', function (): void {
    People::factory()->create([
        'team_id' => $this->team->getKey(),
        'creation_source' => CreationSource::IMPORT,
    ]);

    livewire(ActivationChecklist::class)
        ->assertSeeHtml(stepState('import', true))
        ->assertSeeHtml(stepState('first_record', true));
});

it('completes the invite step while an invitation is pending', function (): void {
    TeamInvitation::query()->create([
        'team_id' => $this->team->getKey(),
        'email' => 'teammate@example.com',
        'role' => TeamRole::Editor->value,
    ]);

    livewire(ActivationChecklist::class)
        ->assertSeeHtml(stepState('invite', true));
});

it('completes the assistant step once the user has sent a chat message', function (): void {
    $conversationId = (string) Str::ulid();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'team_id' => $this->team->getKey(),
        'participant_type' => $this->owner->getMorphClass(),
        'participant_id' => $this->owner->getKey(),
        'title' => 'Pipeline check',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'participant_type' => $this->owner->getMorphClass(),
        'participant_id' => (string) $this->owner->getKey(),
        'role' => 'user',
        'content' => 'hi',
        'agent' => 'crm',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    livewire(ActivationChecklist::class)
        ->assertSeeHtml(stepState('ask_rela', true));
});

it('ignores records and conversations belonging to another workspace', function (): void {
    $otherTeam = Team::factory()->create();

    People::factory()->create([
        'team_id' => $otherTeam->getKey(),
        'creation_source' => CreationSource::WEB,
    ]);

    DB::table('agent_conversations')->insert([
        'id' => (string) Str::ulid(),
        'team_id' => $otherTeam->getKey(),
        'participant_type' => $this->owner->getMorphClass(),
        'participant_id' => $this->owner->getKey(),
        'title' => 'Elsewhere',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    livewire(ActivationChecklist::class)
        ->assertSeeHtml(stepState('first_record', false))
        ->assertSeeHtml(stepState('ask_rela', false));
});

it('disappears once every step is done', function (): void {
    People::factory()->create([
        'team_id' => $this->team->getKey(),
        'creation_source' => CreationSource::IMPORT,
    ]);

    TeamInvitation::query()->create([
        'team_id' => $this->team->getKey(),
        'email' => 'teammate@example.com',
        'role' => TeamRole::Editor->value,
    ]);

    $conversationId = (string) Str::ulid();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'team_id' => $this->team->getKey(),
        'participant_type' => $this->owner->getMorphClass(),
        'participant_id' => $this->owner->getKey(),
        'title' => 'Pipeline check',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'participant_type' => $this->owner->getMorphClass(),
        'participant_id' => (string) $this->owner->getKey(),
        'role' => 'user',
        'content' => 'hi',
        'agent' => 'crm',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    livewire(ActivationChecklist::class)
        ->assertDontSeeHtml('data-testid="activation-step"');
});

it('stays hidden after the owner dismisses it', function (): void {
    livewire(ActivationChecklist::class)
        ->call('dismiss')
        ->assertDontSeeHtml('data-testid="activation-step"');

    expect($this->team->refresh()->activation_checklist_dismissed_at)->not->toBeNull();

    livewire(ActivationChecklist::class)
        ->assertDontSeeHtml('data-testid="activation-step"');
});

it('stays hidden for a member who cannot manage the workspace', function (): void {
    $member = User::factory()->create();
    $this->team->users()->attach($member, ['role' => TeamRole::Editor->value]);

    $this->actingAs($member);
    Filament::setTenant($this->team);

    livewire(ActivationChecklist::class)
        ->assertDontSeeHtml('data-testid="activation-step"');
});

it('mentions sample data only while seeded records remain', function (): void {
    livewire(ActivationChecklist::class)
        ->assertDontSee(__('filament/pages/dashboard.activation.sample_data'));

    People::factory()->create([
        'team_id' => $this->team->getKey(),
        'creation_source' => CreationSource::SYSTEM,
    ]);

    resolve(WorkspaceActivationFacts::class)->forget($this->team);

    livewire(ActivationChecklist::class)
        ->assertSee(__('filament/pages/dashboard.activation.sample_data'));
});

it('answers all four steps without repeating a query', function (): void {
    DB::enableQueryLog();

    livewire(ActivationChecklist::class);

    $log = collect(DB::getQueryLog())->map(fn (array $entry): string => (string) $entry['query']);

    // One `agent_conversations`-joining query is expected, answering the
    // ask_rela fact (hasUserChatMessage), and it does not repeat.
    expect($log->filter(fn (string $sql): bool => str_contains($sql, 'creation_source')))->toHaveCount(1)
        ->and($log->filter(fn (string $sql): bool => str_contains($sql, 'agent_conversations')))->toHaveCount(1)
        ->and($log->filter(fn (string $sql): bool => str_contains($sql, 'team_invitations')))->toHaveCount(1);
});

/**
 * The checklist moved off the dashboard body into the panel sidebar, so it
 * follows the user into every page rather than only existing on Home. Asserted
 * through a real page request because a render hook is not part of the
 * Livewire component under test.
 */
it('renders in the sidebar on every panel page, not just the dashboard', function (): void {
    $this->get(Dashboard::getUrl())
        ->assertOk()
        ->assertSee('data-testid="activation-step"', escape: false);

    $this->get(PeopleResource::getUrl('index'))
        ->assertOk()
        ->assertSee('data-testid="activation-step"', escape: false);
});

/**
 * `?prompt=` seeds the composer and stops. The chat page used to feed that
 * parameter into initialMessage, which sends on arrival: a checklist click
 * would have spent a workspace credit before its owner read what was typed.
 */
it('seeds the composer with the ask_rela question rather than sending it', function (): void {
    livewire(ActivationChecklist::class)
        ->assertSeeHtml(composePromptUrl())
        ->assertDontSeeHtml('href="'.ChatConversation::getUrl().'"');
});

/**
 * A workspace with no records cannot answer a pipeline question, and the
 * assistant spends a tool round-trip discovering that. Only the personal
 * workspace is seeded, so this is the normal state of a second one.
 */
it('asks what the assistant can do while the workspace holds no records', function (): void {
    livewire(ActivationChecklist::class)
        ->assertSeeHtml(composePromptUrl('prompt_empty'))
        ->assertDontSeeHtml(composePromptUrl('prompt'))
        ->assertSee(__('filament/pages/dashboard.activation.steps.ask_rela.label_empty'));
});

/**
 * Seeded demo records are not the team's own, but they are a pipeline the
 * assistant can report on -- so the empty-workspace branch must not key off
 * `hasOwnRecord()`, which is false here too.
 */
it('asks about the pipeline once the workspace holds records, seeded ones included', function (): void {
    People::factory()->create([
        'team_id' => $this->team->getKey(),
        'creation_source' => CreationSource::SYSTEM,
    ]);

    resolve(WorkspaceActivationFacts::class)->forget($this->team);

    livewire(ActivationChecklist::class)
        ->assertSeeHtml(composePromptUrl('prompt'))
        ->assertDontSeeHtml(composePromptUrl('prompt_empty'))
        ->assertSee(__('filament/pages/dashboard.activation.steps.ask_rela.label'));
});

it('shows the invite row to a workspace admin and hides it from an editor', function (): void {
    $this->get(Dashboard::getUrl())
        ->assertOk()
        ->assertSee(__('filament/pages/dashboard.activation.invite_members'));

    $member = User::factory()->create();
    $this->team->users()->attach($member, ['role' => TeamRole::Editor->value]);

    $this->actingAs($member);
    Filament::setTenant($this->team);

    // Members::canAccess() is can('update', $tenant), so this row would link an
    // editor straight to a 403.
    $this->get(Dashboard::getUrl())
        ->assertOk()
        ->assertDontSee(__('filament/pages/dashboard.activation.invite_members'));
});
