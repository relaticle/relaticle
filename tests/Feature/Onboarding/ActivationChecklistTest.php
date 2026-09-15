<?php

declare(strict_types=1);

use App\Actions\Onboarding\DismissActivationChecklist;
use App\Actions\Onboarding\RemoveSampleData;
use App\Actions\Onboarding\StartSetupGreeting;
use App\Enums\CreationSource;
use App\Enums\WorkspaceRole;
use App\Filament\Pages\ChatConversation;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\PeopleResource;
use App\Livewire\App\Onboarding\ActivationChecklist;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Services\WorkspaceActivationFacts;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(ActivationChecklist::class, DismissActivationChecklist::class, RemoveSampleData::class, WorkspaceActivationFacts::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->workspace);
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

function seedSampleRecords(Workspace $workspace, User $owner): void
{
    foreach ([Company::class, People::class, Opportunity::class, Task::class, Note::class] as $model) {
        $model::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'creator_id' => $owner->getKey(),
            'creation_source' => CreationSource::SYSTEM,
        ]);
    }
}

it('starts every step incomplete in a fresh workspace', function (): void {
    livewire(ActivationChecklist::class)
        ->assertSeeHtml(stepState('first_record', false))
        ->assertSeeHtml(stepState('import', false))
        ->assertSeeHtml(stepState('invite', false))
        ->assertSeeHtml(stepState('ask_rela', false))
        ->assertSee('0/4 steps completed');
});

it('completes the first-record step once the workspace holds a record the workspace made', function (): void {
    People::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'creator_id' => $this->owner->getKey(),
        'creation_source' => CreationSource::WEB,
    ]);

    livewire(ActivationChecklist::class)
        ->assertSeeHtml(stepState('first_record', true))
        ->assertSee('1/4 steps completed');
});

it('leaves the first-record step incomplete while only seeded demo records exist', function (): void {
    People::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'creation_source' => CreationSource::SYSTEM,
    ]);

    livewire(ActivationChecklist::class)
        ->assertSeeHtml(stepState('first_record', false))
        ->assertSee('0/4 steps completed');
});

it('completes the import step for an imported record', function (): void {
    People::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'creation_source' => CreationSource::IMPORT,
    ]);

    livewire(ActivationChecklist::class)
        ->assertSeeHtml(stepState('import', true))
        ->assertSeeHtml(stepState('first_record', true));
});

it('completes the invite step while an invitation is pending', function (): void {
    WorkspaceInvitation::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'email' => 'teammate@example.com',
        'role' => WorkspaceRole::Editor->value,
    ]);

    livewire(ActivationChecklist::class)
        ->assertSeeHtml(stepState('invite', true));
});

it('completes the assistant step once the user has sent a chat message', function (): void {
    $conversationId = (string) Str::ulid();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'workspace_id' => $this->workspace->getKey(),
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

it('leaves the assistant step open for a prompt the user never typed', function (): void {
    $conversationId = (string) Str::ulid();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'workspace_id' => $this->workspace->getKey(),
        'participant_type' => $this->owner->getMorphClass(),
        'participant_id' => $this->owner->getKey(),
        'title' => 'Set up your workspace',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'participant_type' => $this->owner->getMorphClass(),
        'participant_id' => (string) $this->owner->getKey(),
        'role' => 'user',
        'content' => StartSetupGreeting::PROMPT,
        'agent' => 'crm',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => json_encode(['kind' => 'continuation']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    livewire(ActivationChecklist::class)
        ->assertSeeHtml(stepState('ask_rela', false));
});

it('ignores records and conversations belonging to another workspace', function (): void {
    $otherWorkspace = Workspace::factory()->create();

    People::factory()->create([
        'workspace_id' => $otherWorkspace->getKey(),
        'creation_source' => CreationSource::WEB,
    ]);

    DB::table('agent_conversations')->insert([
        'id' => (string) Str::ulid(),
        'workspace_id' => $otherWorkspace->getKey(),
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
        'workspace_id' => $this->workspace->getKey(),
        'creation_source' => CreationSource::IMPORT,
    ]);

    WorkspaceInvitation::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'email' => 'teammate@example.com',
        'role' => WorkspaceRole::Editor->value,
    ]);

    $conversationId = (string) Str::ulid();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'workspace_id' => $this->workspace->getKey(),
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

    expect($this->workspace->refresh()->activation_checklist_dismissed_at)->not->toBeNull();

    livewire(ActivationChecklist::class)
        ->assertDontSeeHtml('data-testid="activation-step"');
});

it('stays hidden for a member who cannot manage the workspace', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Editor->value]);

    $this->actingAs($member);
    Filament::setTenant($this->workspace);

    livewire(ActivationChecklist::class)
        ->assertDontSeeHtml('data-testid="activation-step"');
});

it('mentions sample data only while seeded records remain', function (): void {
    livewire(ActivationChecklist::class)
        ->assertDontSee(__('filament/pages/dashboard.activation.sample_data'));

    People::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'creation_source' => CreationSource::SYSTEM,
    ]);

    resolve(WorkspaceActivationFacts::class)->forget($this->workspace);

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
        ->and($log->filter(fn (string $sql): bool => str_contains($sql, 'workspace_invitations')))->toHaveCount(1);
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
 * Seeded demo records are not the workspace's own, but they are a pipeline the
 * assistant can report on -- so the empty-workspace branch must not key off
 * `hasOwnRecord()`, which is false here too.
 */
it('asks about the pipeline once the workspace holds records, seeded ones included', function (): void {
    People::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'creation_source' => CreationSource::SYSTEM,
    ]);

    resolve(WorkspaceActivationFacts::class)->forget($this->workspace);

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
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Editor->value]);

    $this->actingAs($member);
    Filament::setTenant($this->workspace);

    // Members::canAccess() is can('update', $tenant), so this row would link an
    // editor straight to a 403.
    $this->get(Dashboard::getUrl())
        ->assertOk()
        ->assertDontSee(__('filament/pages/dashboard.activation.invite_members'));
});

it('offers to remove sample data only once the workspace has an own record', function (): void {
    seedSampleRecords($this->workspace, $this->owner);

    livewire(ActivationChecklist::class)
        ->assertSet('canRemoveSampleData', false)
        ->assertDontSee(__('filament/pages/dashboard.activation.remove_sample_data'));

    People::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'creator_id' => $this->owner->getKey(),
        'creation_source' => CreationSource::WEB,
    ]);

    resolve(WorkspaceActivationFacts::class)->forget($this->workspace);

    livewire(ActivationChecklist::class)
        ->assertSet('canRemoveSampleData', true)
        ->assertSee(__('filament/pages/dashboard.activation.remove_sample_data'));
});

it('removes every system record and keeps the workspace\'s own', function (): void {
    seedSampleRecords($this->workspace, $this->owner);
    $own = People::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'creator_id' => $this->owner->getKey(),
        'creation_source' => CreationSource::WEB,
    ]);

    livewire(ActivationChecklist::class)
        ->call('removeSampleData')
        ->assertSet('canRemoveSampleData', false)
        ->assertRedirect(Dashboard::getUrl());

    foreach ([Company::class, People::class, Opportunity::class, Task::class, Note::class] as $model) {
        expect($model::query()->where('workspace_id', $this->workspace->getKey())->where('creation_source', CreationSource::SYSTEM)->exists())->toBeFalse();
    }

    expect(People::query()->whereKey($own->getKey())->exists())->toBeTrue()
        ->and(resolve(WorkspaceActivationFacts::class)->hasSampleData($this->workspace->fresh()))->toBeFalse();
});

it('refuses removal while the workspace has no own record', function (): void {
    seedSampleRecords($this->workspace, $this->owner);

    livewire(ActivationChecklist::class)
        ->call('removeSampleData')
        ->assertStatus(422);

    foreach ([Company::class, People::class, Opportunity::class, Task::class, Note::class] as $model) {
        expect($model::query()->where('workspace_id', $this->workspace->getKey())->where('creation_source', CreationSource::SYSTEM)->exists())->toBeTrue();
    }
});

it('hides the checklist from a non-owner admin and refuses the call', function (): void {
    seedSampleRecords($this->workspace, $this->owner);
    People::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'creator_id' => $this->owner->getKey(),
        'creation_source' => CreationSource::WEB,
    ]);

    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);

    $this->actingAs($admin);
    Filament::setTenant($this->workspace);

    livewire(ActivationChecklist::class)
        ->assertSet('visible', false)
        ->assertSet('canRemoveSampleData', false)
        ->assertDontSee(__('filament/pages/dashboard.activation.remove_sample_data'));

    try {
        resolve(RemoveSampleData::class)->execute($admin, $this->workspace);

        $this->fail('Expected an HttpException.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }

    foreach ([Company::class, People::class, Opportunity::class, Task::class, Note::class] as $model) {
        expect($model::query()->where('workspace_id', $this->workspace->getKey())->where('creation_source', CreationSource::SYSTEM)->exists())->toBeTrue();
    }
});

it('refuses removal from the owner of a different workspace', function (): void {
    seedSampleRecords($this->workspace, $this->owner);
    $intruder = User::factory()->withPersonalWorkspace()->create();

    try {
        resolve(RemoveSampleData::class)->execute($intruder, $this->workspace);

        $this->fail('Expected an HttpException.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }

    foreach ([Company::class, People::class, Opportunity::class, Task::class, Note::class] as $model) {
        expect($model::query()->where('workspace_id', $this->workspace->getKey())->where('creation_source', CreationSource::SYSTEM)->exists())->toBeTrue();
    }
});

it('refuses sample removal from a member', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Editor->value]);

    resolve(RemoveSampleData::class)->execute($member, $this->workspace);
})->throws(HttpException::class);
