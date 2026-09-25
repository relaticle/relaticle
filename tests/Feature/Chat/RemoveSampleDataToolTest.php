<?php

declare(strict_types=1);

use App\Actions\Onboarding\RemoveSampleData;
use App\Enums\CreationSource;
use App\Enums\WorkspaceRole;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Livewire\Livewire;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Livewire\Chat\ProposalCard;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Tools\Workspace\RemoveSampleDataTool;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(RemoveSampleDataTool::class, RemoveSampleData::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);
});

function seedChatSampleRecords(Workspace $workspace, User $owner): void
{
    foreach ([Company::class, Company::class, People::class, Opportunity::class, Task::class, Note::class] as $model) {
        $model::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'creator_id' => $owner->getKey(),
            'creation_source' => CreationSource::SYSTEM,
        ]);
    }
}

function remainingSampleRecords(Workspace $workspace): int
{
    return collect([Company::class, People::class, Opportunity::class, Task::class, Note::class])
        ->sum(fn (string $model): int => $model::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('creation_source', CreationSource::SYSTEM)
            ->count());
}

function proposeSampleRemoval(): array
{
    return json_decode(resolve(RemoveSampleDataTool::class)->handle(new Request([])), true);
}

it('proposes one removal listing the sample count of every entity', function (): void {
    seedChatSampleRecords($this->workspace, $this->owner);

    $result = proposeSampleRemoval();

    $pending = PendingAction::query()->where('workspace_id', $this->workspace->getKey())->sole();

    expect($result['type'])->toBe('pending_action')
        ->and($pending->action_class)->toBe(RemoveSampleData::class)
        ->and($pending->operation)->toBe(PendingActionOperation::Delete)
        ->and($pending->entity_type)->toBe('sample_data')
        ->and($pending->display_data['summary'])->toBe('Delete 6 sample records')
        ->and(collect($pending->display_data['fields'])->pluck('value', 'label')->all())->toBe([
            'Companies' => '2',
            'People' => '1',
            'Tasks' => '1',
            'Notes' => '1',
            'Opportunities' => '1',
        ]);
});

it('removes every sample record when approved from the card, even with no records of the user\'s own', function (): void {
    seedChatSampleRecords($this->workspace, $this->owner);
    proposeSampleRemoval();

    $pending = PendingAction::query()->where('workspace_id', $this->workspace->getKey())->sole();

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $pending->getKey(), context: 'conversation')
        ->call('createCurrent')
        ->assertDispatched('proposal:resolved');

    expect(remainingSampleRecords($this->workspace))->toBe(0)
        ->and($pending->fresh()->status)->toBe(PendingActionStatus::Approved);
});

it('keeps the user\'s own records when the sample data is removed', function (): void {
    seedChatSampleRecords($this->workspace, $this->owner);
    $own = Company::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'creator_id' => $this->owner->getKey(),
        'creation_source' => CreationSource::CHAT,
    ]);

    proposeSampleRemoval();

    resolve(PendingActionService::class)->approve(
        PendingAction::query()->where('workspace_id', $this->workspace->getKey())->sole(),
        $this->owner,
    );

    expect(remainingSampleRecords($this->workspace))->toBe(0)
        ->and(Company::query()->whereKey($own->getKey())->exists())->toBeTrue();
});

it('names the approved removal for the assistant instead of leaving it unnamed', function (): void {
    seedChatSampleRecords($this->workspace, $this->owner);
    proposeSampleRemoval();

    $pending = PendingAction::query()->where('workspace_id', $this->workspace->getKey())->sole();

    expect(resolve(PendingActionService::class)->resolveActionLabel($pending))->toBe('All sample records');
});

it('refuses a member who does not own the workspace and proposes nothing', function (): void {
    seedChatSampleRecords($this->workspace, $this->owner);

    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);
    $admin->switchWorkspace($this->workspace);
    $this->actingAs($admin);

    $result = proposeSampleRemoval();

    expect($result['error'])->toContain('Only the workspace owner')
        ->and(PendingAction::query()->count())->toBe(0);
});

it('refuses when no sample records remain', function (): void {
    Company::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'creator_id' => $this->owner->getKey(),
        'creation_source' => CreationSource::WEB,
    ]);

    $result = proposeSampleRemoval();

    expect($result['error'])->toContain('no sample records')
        ->and(PendingAction::query()->count())->toBe(0);
});

it('refuses to approve the removal from another workspace', function (): void {
    seedChatSampleRecords($this->workspace, $this->owner);
    proposeSampleRemoval();

    $pending = PendingAction::query()->where('workspace_id', $this->workspace->getKey())->sole();
    $outsider = User::factory()->withPersonalWorkspace()->create();

    expect(fn () => resolve(PendingActionService::class)->approve($pending, $outsider))
        ->toThrow(RuntimeException::class, 'This action belongs to another workspace.');

    expect(remainingSampleRecords($this->workspace))->toBe(6)
        ->and($pending->fresh()->status)->toBe(PendingActionStatus::Pending);
});

it('refuses an approval by a workspace admin who is not the owner', function (): void {
    seedChatSampleRecords($this->workspace, $this->owner);
    proposeSampleRemoval();

    $pending = PendingAction::query()->where('workspace_id', $this->workspace->getKey())->sole();
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);
    $admin->switchWorkspace($this->workspace);

    expect(fn () => resolve(PendingActionService::class)->approve($pending, $admin))
        ->toThrow(HttpException::class);

    expect(remainingSampleRecords($this->workspace))->toBe(6)
        ->and($pending->fresh()->status)->toBe(PendingActionStatus::Pending);
});

it('offers sample removal in the setup conversation too', function (): void {
    $toolClasses = array_map(
        static fn (Tool $tool): string => $tool::class,
        (new CrmAssistant)->withSetupMode(true)->tools(),
    );

    expect($toolClasses)->toContain(RemoveSampleDataTool::class);
});
