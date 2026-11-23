<?php

declare(strict_types=1);

use App\Actions\Merge\MergeRecords;
use App\Enums\WorkspaceRole;
use App\Features\OnboardSeed;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldSection;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Exceptions\ProposalNotResolvable;
use Relaticle\Chat\Livewire\Chat\ProposalCard;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Support\ProposalProgress;
use Relaticle\Chat\Support\RecordReferenceResolver;
use Relaticle\Chat\Support\ResolvedActionText;
use Relaticle\Chat\Tools\BaseMergeTool;
use Relaticle\Chat\Tools\Company\MergeCompaniesTool;
use Relaticle\Chat\Tools\People\MergePeopleTool;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;

mutates(BaseMergeTool::class, MergeCompaniesTool::class, MergePeopleTool::class, PendingActionService::class);

dataset('mergeable entities', [
    'company' => [Company::class, MergeCompaniesTool::class, 'company'],
    'people' => [People::class, MergePeopleTool::class, 'people'],
]);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    Bus::fake();
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->actingAs($this->user);
    Auth::guard('web')->setUser($this->user);
    Filament::setTenant($this->workspace);

    $this->pairs = function (string $model, int $count, array $attributes = []) {
        return collect(range(1, $count))->map(fn (int $number): array => [
            $model::factory()->create(['workspace_id' => $this->workspace->getKey(), 'creator_id' => $this->user->getKey(), 'name' => "Primary {$number}", ...$attributes]),
            $model::factory()->create(['workspace_id' => $this->workspace->getKey(), 'creator_id' => $this->user->getKey(), 'name' => "Secondary {$number}", ...$attributes]),
        ])->all();
    };

    $this->conversationId = '019dfa00-5555-7000-8000-0000000000f1';
    DB::table('agent_conversations')->insert([
        'id' => $this->conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $this->user->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->propose = function (string $tool, array $pairs): array {
        $merges = array_map(fn (array $pair): array => ['primary_id' => $pair[0]->getKey(), 'secondary_id' => $pair[1]->getKey()], $pairs);
        $instance = resolve($tool);
        $instance->setConversationId($this->conversationId);

        return json_decode($instance->handle(new Request(['merges' => $merges])), true);
    };

    $this->proposal = fn (): PendingAction => PendingAction::query()->where('user_id', $this->user->getKey())->sole();
});

it('proposes one pair as a plain proposal', function (string $model, string $tool, string $entity): void {
    [$pair] = ($this->pairs)($model, 1);

    $envelope = ($this->propose)($tool, [$pair]);
    $pending = ($this->proposal)();

    expect($pending->operation)->toBe(PendingActionOperation::Merge)
        ->and($pending->entity_type->value)->toBe($entity)
        ->and($pending->action_data)->toEqualCanonicalizing(['_record_id' => $pair[0]->getKey(), '_model_class' => $model, '_merge_with' => $pair[1]->getKey()])
        ->and($pending->display_data['name'])->toBe('Secondary 1 into Primary 1')
        ->and($envelope['operation'])->toBe('merge')
        ->and($envelope['meta']['agent_should_stop'])->toBeTrue();
})->with('mergeable entities');

it('proposes two or more pairs as one batch', function (string $model, string $tool): void {
    $pairs = ($this->pairs)($model, 3);

    ($this->propose)($tool, $pairs);
    $pending = ($this->proposal)();

    expect($pending->action_data['_batch'])->toBeTrue()
        ->and($pending->action_data['records'])->toHaveCount(3)
        ->and(collect($pending->action_data['records'])->pluck('_merge_with')->all())->toBe(collect($pairs)->map(fn (array $pair): string => $pair[1]->getKey())->all())
        ->and(collect($pending->display_data['items'])->pluck('name')->all())->toBe(['Secondary 1 into Primary 1', 'Secondary 2 into Primary 2', 'Secondary 3 into Primary 3']);
})->with('mergeable entities');

it('shows each pair with both names on a card that reads as a merge', function (string $model, string $tool): void {
    $pairs = ($this->pairs)($model, 2);
    ($this->propose)($tool, $pairs);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: ($this->proposal)()->getKey(), context: 'conversation')
        ->assertSeeText('Secondary 1 into Primary 1')
        ->assertSeeText('Secondary 2 into Primary 2')
        ->assertSeeText('Merge 2 pairs')
        ->assertSeeText('Merge')
        ->assertDontSeeText('Delete')
        ->assertDontSeeHtml('toggleField');
})->with('mergeable entities');

it('shows merges inside a plan as merge steps with no field rows', function (): void {
    foreach ([[Company::class, MergeCompaniesTool::class], [People::class, MergePeopleTool::class]] as [$model, $toolClass]) {
        [$pair] = ($this->pairs)($model, 1);
        $tool = resolve($toolClass);
        $tool->setConversationId($this->conversationId);
        $tool->setTurnId('01TURNAAAAAAAAAAAAAAAAAAAE');
        $tool->handle(new Request(['merges' => [['primary_id' => $pair[0]->getKey(), 'secondary_id' => $pair[1]->getKey()]]]));
    }

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->call('setActive', PendingAction::query()->where('user_id', $this->user->getKey())->firstOrFail()->getKey())
        ->assertSeeText('Secondary 1 into Primary 1')
        ->assertSeeText('Merge')
        ->assertDontSeeText('Name')
        ->assertDontSeeHtml('toggleField');
});

it('approves one pair and leaves the others pending', function (string $model, string $tool): void {
    $pairs = ($this->pairs)($model, 2);
    ($this->propose)($tool, $pairs);
    $pending = ($this->proposal)();

    $result = resolve(PendingActionService::class)->approveItem($pending, $this->user, 0);

    expect($result['finalized'])->toBeFalse()
        ->and($model::query()->whereKey($pairs[0][0]->getKey())->exists())->toBeFalse()
        ->and($model::query()->whereKey($pairs[0][1]->getKey())->exists())->toBeFalse()
        ->and($model::query()->whereKey($pairs[1][0]->getKey())->exists())->toBeTrue()
        ->and($model::query()->whereKey($pairs[1][1]->getKey())->exists())->toBeTrue()
        ->and($model::query()->where('workspace_id', $this->workspace->getKey())->where('name', 'Primary 1')->count())->toBe(1)
        ->and($pending->fresh()->status)->toBe(PendingActionStatus::Pending);
})->with('mergeable entities');

it('merges every pair when the whole batch is approved', function (string $model, string $tool): void {
    $pairs = ($this->pairs)($model, 2);
    ($this->propose)($tool, $pairs);
    $pending = ($this->proposal)();

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->call('setActive', $pending->getKey())
        ->call('createCurrent');

    expect($pending->fresh()->status)->toBe(PendingActionStatus::Approved)
        ->and($model::query()->where('workspace_id', $this->workspace->getKey())->pluck('name')->sort()->values()->all())->toBe(['Primary 1', 'Primary 2']);
})->with('mergeable entities');

it('merges a single pair on approval', function (string $model, string $tool): void {
    [$pair] = ($this->pairs)($model, 1);
    ($this->propose)($tool, [$pair]);

    resolve(PendingActionService::class)->approve(($this->proposal)(), $this->user);

    expect($model::query()->whereKey([$pair[0]->getKey(), $pair[1]->getKey()])->count())->toBe(0)
        ->and($model::query()->where('workspace_id', $this->workspace->getKey())->where('name', 'Primary 1')->count())->toBe(1);
})->with('mergeable entities');

it('fails only the pair whose record vanished before approval', function (string $model, string $tool): void {
    $pairs = ($this->pairs)($model, 3);
    ($this->propose)($tool, $pairs);
    $pending = ($this->proposal)();
    $pairs[1][1]->forceDelete();
    $service = resolve(PendingActionService::class);

    $service->approveItem($pending, $this->user, 0);
    expect(fn () => $service->approveItem($pending, $this->user, 1))->toThrow(ProposalNotResolvable::class);
    $service->approveItem($pending, $this->user, 2);

    expect($model::query()->whereKey($pairs[1][0]->getKey())->exists())->toBeTrue()
        ->and($model::query()->whereKey($pairs[2][0]->getKey())->exists())->toBeFalse();
})->with('mergeable entities');

it('fails a pair whose secondary was merged into another record before approval', function (string $model, string $tool): void {
    $pairs = ($this->pairs)($model, 2);
    ($this->propose)($tool, $pairs);
    $pending = ($this->proposal)();
    $extra = $model::factory()->create(['workspace_id' => $this->workspace->getKey(), 'creator_id' => $this->user->getKey(), 'name' => 'Extra']);
    resolve(MergeRecords::class)->execute($this->user, $extra, $pairs[0][1]);

    expect(fn () => resolve(PendingActionService::class)->approveItem($pending, $this->user, 0))->toThrow(ProposalNotResolvable::class)
        ->and($model::query()->whereKey($pairs[0][0]->getKey())->exists())->toBeTrue();
})->with('mergeable entities');

it('changes nothing when the proposal is rejected', function (string $model, string $tool): void {
    $pairs = ($this->pairs)($model, 2);
    ($this->propose)($tool, $pairs);
    $pending = ($this->proposal)();

    resolve(PendingActionService::class)->reject($pending, $this->user);

    expect($pending->fresh()->status)->toBe(PendingActionStatus::Rejected)
        ->and($model::query()->where('workspace_id', $this->workspace->getKey())->count())->toBe(4);
})->with('mergeable entities');

it('proposes nothing for a member who may not create, update and delete', function (string $model, string $tool): void {
    $pairs = ($this->pairs)($model, 1);
    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($this->workspace);
    $this->actingAs($viewer->fresh());
    Auth::guard('web')->setUser($viewer->fresh());

    $result = resolve($tool)->handle(new Request(['merges' => [['primary_id' => $pairs[0][0]->getKey(), 'secondary_id' => $pairs[0][1]->getKey()]]]));

    expect($result)->toContain('role does not allow')
        ->and(PendingAction::query()->count())->toBe(0);
})->with('mergeable entities');

it('refuses a record that sits in two pairs', function (string $model, string $tool): void {
    [[$first, $second], [$third]] = ($this->pairs)($model, 2);

    $result = ($this->propose)($tool, [[$first, $second], [$first, $third]]);

    expect($result)->toHaveKey('error')
        ->and(PendingAction::query()->count())->toBe(0);
})->with('mergeable entities');

it('refuses a pair of one record with itself', function (string $model, string $tool): void {
    [[$first]] = ($this->pairs)($model, 1);

    expect(($this->propose)($tool, [[$first, $first]]))->toHaveKey('error')
        ->and(PendingAction::query()->count())->toBe(0);
})->with('mergeable entities');

it('skips a pair from another workspace and proposes the rest', function (string $model, string $tool): void {
    [$mine] = ($this->pairs)($model, 1);
    $stranger = User::factory()->withPersonalWorkspace()->create();
    $foreign = $model::factory()->create(['workspace_id' => $stranger->currentWorkspace->getKey(), 'creator_id' => $stranger->getKey()]);
    $other = $model::factory()->create(['workspace_id' => $this->workspace->getKey(), 'creator_id' => $this->user->getKey()]);

    $envelope = ($this->propose)($tool, [$mine, [$other, $foreign]]);

    expect($envelope['skipped'])->toBe([['primary_id' => $other->getKey(), 'secondary_id' => $foreign->getKey()]])
        ->and(($this->proposal)()->action_data)->toHaveKey('_merge_with');
})->with('mergeable entities');

it('offers the merge tools in a normal chat and drops them in setup mode', function (): void {
    $names = fn (CrmAssistant $agent): array => array_map(fn (object $tool): string => $tool::class, $agent->tools());

    expect($names(new CrmAssistant))->toContain(MergeCompaniesTool::class, MergePeopleTool::class)
        ->and($names((new CrmAssistant)->withSetupMode(true)))->not->toContain(MergeCompaniesTool::class)
        ->not->toContain(MergePeopleTool::class);
});

it('shows a single pair without field rows', function (string $model, string $tool): void {
    [$pair] = ($this->pairs)($model, 1);
    ($this->propose)($tool, [$pair]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->call('setActive', ($this->proposal)()->getKey())
        ->assertSeeText('Secondary 1 into Primary 1')
        ->assertDontSeeHtml('data-proposal-field-row');
})->with('mergeable entities');

it('lets the assistant cite the new merged records', function (string $model, string $tool): void {
    $pairs = ($this->pairs)($model, 2);
    ($this->propose)($tool, $pairs);
    $pending = ($this->proposal)();
    resolve(PendingActionService::class)->approveItem($pending, $this->user, 0);
    resolve(PendingActionService::class)->approveItem($pending, $this->user, 1);

    $merged = $model::query()->where('workspace_id', $this->workspace->getKey())->orderBy('name')->get();
    $resolver = resolve(RecordReferenceResolver::class);

    expect(resolve(PendingActionService::class)->resolvedForConversation($this->conversationId, null)[0]['records'])->toEqualCanonicalizing([
        ['id' => $merged[0]->getKey(), 'label' => 'Primary 1', 'url' => $resolver->referenceUrl($pending->entity_type->value, $merged[0]->getKey())],
        ['id' => $merged[1]->getKey(), 'label' => 'Primary 2', 'url' => $resolver->referenceUrl($pending->entity_type->value, $merged[1]->getKey())],
    ]);
})->with('mergeable entities');

it('cites the new record of a single approved pair', function (string $model, string $tool): void {
    [$pair] = ($this->pairs)($model, 1);
    ($this->propose)($tool, [$pair]);
    resolve(PendingActionService::class)->approve(($this->proposal)(), $this->user);

    $merged = $model::query()->where('workspace_id', $this->workspace->getKey())->sole();

    expect(resolve(PendingActionService::class)->resolvedForConversation($this->conversationId, null)[0]['records'][0])
        ->toMatchArray(['id' => $merged->getKey(), 'label' => 'Primary 1']);
})->with('mergeable entities');

it('tells the assistant what the user decided about a merge', function (): void {
    [$first] = ($this->pairs)(Company::class, 1);
    ($this->propose)(MergeCompaniesTool::class, [$first]);
    $service = resolve(PendingActionService::class);
    $service->approve(($this->proposal)(), $this->user);
    [$second] = ($this->pairs)(People::class, 1);
    ($this->propose)(MergePeopleTool::class, [$second]);
    $service->reject(PendingAction::query()->where('entity_type', 'people')->sole(), $this->user);

    $lines = collect($service->resolvedForConversation($this->conversationId, null))
        ->flatMap(fn (array $action): array => ResolvedActionText::lines($action, cite: true))
        ->implode("\n");

    expect($lines)->toContain('APPROVED')->toContain('merge company')->toContain('Primary 1')
        ->and($lines)->toContain('REJECTED')->toContain('merge people')->toContain('"Secondary 1 into Primary 1"');
});

it('stops at the pair whose record vanished and names it when approving through the card', function (string $model, string $tool): void {
    $pairs = ($this->pairs)($model, 3);
    ($this->propose)($tool, $pairs);
    $pending = ($this->proposal)();
    $pairs[1][1]->forceDelete();

    $card = Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->call('setActive', $pending->getKey())
        ->call('createCurrent');

    expect($card->errors()->get('resolve')[0])->toContain('Secondary 2 into Primary 2')->toContain('could not be completed')->toContain('Record not found')
        ->and($model::query()->whereKey($pairs[0][0]->getKey())->exists())->toBeFalse()
        ->and($model::query()->whereKey($pairs[1][0]->getKey())->exists())->toBeTrue()
        ->and($model::query()->whereKey($pairs[2][0]->getKey())->exists())->toBeTrue()
        ->and($pending->fresh()->status)->toBe(PendingActionStatus::Pending)
        ->and(ProposalProgress::for($pending->fresh())->unresolvedIndices())->toBe([1, 2]);
})->with('mergeable entities');

it('merges nothing once the member lost the right to update or delete', function (string $model, string $tool, WorkspaceRole $role): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Admin->value]);
    $member->switchWorkspace($this->workspace);
    $this->actingAs($member->fresh());
    Auth::guard('web')->setUser($member->fresh());
    [$pair] = ($this->pairs)($model, 1);
    ($this->propose)($tool, [$pair]);
    $pending = PendingAction::query()->sole();
    $this->workspace->users()->updateExistingPivot($member->getKey(), ['role' => $role->value]);
    $this->actingAs($member->fresh());
    Auth::guard('web')->setUser($member->fresh());

    $card = Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->call('setActive', $pending->getKey())
        ->call('createCurrent');

    expect($card->errors()->get('resolve'))->toBe([__('You no longer have permission to make this change.')])
        ->and($model::query()->whereKey([$pair[0]->getKey(), $pair[1]->getKey()])->count())->toBe(2)
        ->and($pending->fresh()->status)->toBe(PendingActionStatus::Pending);
})->with([
    'company viewer' => [Company::class, MergeCompaniesTool::class, WorkspaceRole::Viewer],
    'people viewer' => [People::class, MergePeopleTool::class, WorkspaceRole::Viewer],
]);

it('shows a card message when the combined values no longer fit', function (): void {
    $section = CustomFieldSection::query()->create([
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'people',
        'name' => 'Contact',
        'code' => 'contact',
        'type' => 'section',
        'sort_order' => 99,
        'active' => true,
    ]);
    $emails = CustomField::query()->create([
        'tenant_id' => $this->workspace->getKey(),
        'custom_field_section_id' => $section->getKey(),
        'entity_type' => 'people',
        'code' => 'work_emails',
        'name' => 'Work emails',
        'type' => 'email',
        'sort_order' => 99,
        'active' => true,
        'validation_rules' => [],
        'settings' => new CustomFieldSettingsData(allow_multiple: true, max_values: 20),
    ]);
    [$pair] = ($this->pairs)(People::class, 1);
    ($this->propose)(MergePeopleTool::class, [$pair]);
    $pending = ($this->proposal)();
    $pair[0]->saveCustomFieldValue($emails, array_map(fn (int $number): string => "a{$number}@one.test", range(1, 11)));
    $pair[1]->saveCustomFieldValue($emails, array_map(fn (int $number): string => "b{$number}@two.test", range(1, 11)));

    $card = Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->call('setActive', $pending->getKey())
        ->call('createCurrent');

    expect($card->errors()->get('resolve')[0])->toContain('can hold at most 20')
        ->and(People::query()->whereKey([$pair[0]->getKey(), $pair[1]->getKey()])->count())->toBe(2);
});

it('reads as a merge in the dock with a danger button and no shortcut', function (string $model, string $tool): void {
    $pairs = ($this->pairs)($model, 1);
    ($this->propose)($tool, $pairs);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->call('setActive', ($this->proposal)()->getKey())
        ->assertSeeHtml('<span class="text-gray-500 dark:text-gray-400">Merge</span>')
        ->assertSeeText('Merge 1 pair')
        ->assertSeeHtml('bg-red-600 text-white')
        ->assertDontSeeHtml('bg-primary-600 text-white')
        ->assertDontSeeHtml('data-proposal-shortcut')
        ->assertDontSeeHtml('<kbd');
})->with('mergeable entities');
