<?php

declare(strict_types=1);

use App\Models\ActivityLog\Activity;
use App\Models\ActivityLog\Scopes\WorkspaceScope;
use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Relaticle\SystemAdmin\Filament\Resources\ActivityResource\Pages\ListActivities;
use Relaticle\SystemAdmin\Filament\Resources\ActivityResource\Pages\ViewActivity;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

/**
 * @param  array<string, mixed>  $attributes
 */
function seedActivity(Workspace $workspace, User $causer, array $attributes = []): Activity
{
    return Activity::withoutGlobalScope(WorkspaceScope::class)->create(array_merge([
        'log_name' => 'crm',
        'description' => 'created',
        'event' => 'created',
        'subject_type' => 'company',
        'subject_id' => Company::withoutEvents(fn (): Company => Company::factory()->create())->id,
        'causer_type' => 'user',
        'causer_id' => $causer->id,
        'workspace_id' => $workspace->id,
        'properties' => [],
    ], $attributes));
}

mutates(Activity::class);

beforeEach(function (): void {
    $this->admin = SystemAdministrator::factory()->create();
    $this->actingAs($this->admin, 'sysadmin');
    Filament::setCurrentPanel('sysadmin');

    $this->ownerA = User::factory()->withWorkspace()->create();
    $this->workspaceA = $this->ownerA->currentWorkspace;
    $this->ownerB = User::factory()->withWorkspace()->create();
    $this->workspaceB = $this->ownerB->currentWorkspace;
});

it('shows activity across all tenants on the list page', function (): void {
    $a = seedActivity($this->workspaceA, $this->ownerA, ['description' => 'created company A']);
    $b = seedActivity($this->workspaceB, $this->ownerB, ['description' => 'created company B']);

    livewire(ListActivities::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$a, $b]);
});

it('filters activity by workspace', function (): void {
    $a = seedActivity($this->workspaceA, $this->ownerA);
    $b = seedActivity($this->workspaceB, $this->ownerB);

    livewire(ListActivities::class)
        ->filterTable('workspace_id', $this->workspaceA->id)
        ->assertCanSeeTableRecords([$a])
        ->assertCanNotSeeTableRecords([$b]);
});

it('filters activity by event', function (): void {
    $created = seedActivity($this->workspaceA, $this->ownerA, ['event' => 'created', 'description' => 'created']);
    $deleted = seedActivity($this->workspaceA, $this->ownerA, ['event' => 'deleted', 'description' => 'deleted']);

    livewire(ListActivities::class)
        ->filterTable('event', 'deleted')
        ->assertCanSeeTableRecords([$deleted])
        ->assertCanNotSeeTableRecords([$created]);
});

it('filters activity by causer', function (): void {
    $otherUser = User::factory()->create();
    $mine = seedActivity($this->workspaceA, $this->ownerA, ['description' => 'by owner A']);
    $theirs = seedActivity($this->workspaceA, $otherUser, ['description' => 'by other user']);

    livewire(ListActivities::class)
        ->filterTable('causer', $this->ownerA->id)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('filters activity by date range', function (): void {
    $inRange = $this->travelTo('2026-06-15 12:00:00', fn (): Activity => seedActivity($this->workspaceA, $this->ownerA, ['description' => 'in range']));
    $outOfRange = $this->travelTo('2026-06-01 12:00:00', fn (): Activity => seedActivity($this->workspaceA, $this->ownerA, ['description' => 'out of range']));

    livewire(ListActivities::class)
        ->filterTable('created_at', ['from' => '2026-06-10', 'until' => '2026-06-20'])
        ->assertCanSeeTableRecords([$inRange])
        ->assertCanNotSeeTableRecords([$outOfRange]);
});

it('filters activity by the date range on the administrator calendar, not the server one', function (): void {
    $this->admin->forceFill(['timezone' => 'Asia/Yerevan'])->save();
    $this->actingAs($this->admin->refresh(), 'sysadmin');

    // 01:30 on Jun 10 in Yerevan, still Jun 9 on the server.
    $justInside = $this->travelTo('2026-06-09 21:30:00', fn (): Activity => seedActivity($this->workspaceA, $this->ownerA, ['description' => 'just inside']));
    // 01:30 on Jun 21 in Yerevan, still Jun 20 on the server.
    $justOutside = $this->travelTo('2026-06-20 21:30:00', fn (): Activity => seedActivity($this->workspaceA, $this->ownerA, ['description' => 'just outside']));

    livewire(ListActivities::class)
        ->filterTable('created_at', ['from' => '2026-06-10', 'until' => '2026-06-20'])
        ->assertCanSeeTableRecords([$justInside])
        ->assertCanNotSeeTableRecords([$justOutside]);
});

it('renders the view page with a standard attribute diff', function (): void {
    $activity = seedActivity($this->workspaceA, $this->ownerA, [
        'event' => 'updated',
        'description' => 'updated',
        'properties' => ['attributes' => ['name' => 'New Co'], 'old' => ['name' => 'Old Co']],
    ]);

    livewire(ViewActivity::class, [
        'record' => $activity->getKey(),
    ])
        ->assertOk()
        ->assertSee('Old Co')
        ->assertSee('New Co');
});

it('renders the native diff stored in attribute_changes for an updated activity', function (): void {
    $activity = seedActivity($this->workspaceA, $this->ownerA, [
        'event' => 'updated',
        'description' => 'updated',
        'properties' => [],
        'attribute_changes' => ['attributes' => ['name' => 'New Co'], 'old' => ['name' => 'Old Co']],
    ]);

    livewire(ViewActivity::class, [
        'record' => $activity->getKey(),
    ])
        ->assertOk()
        ->assertSee('Old Co')
        ->assertSee('New Co')
        ->assertDontSee('No field changes recorded');
});

it('renders the native initial values stored in attribute_changes for a created activity', function (): void {
    $activity = seedActivity($this->workspaceA, $this->ownerA, [
        'event' => 'created',
        'description' => 'created',
        'properties' => [],
        'attribute_changes' => ['attributes' => ['name' => 'Fresh Co']],
    ]);

    livewire(ViewActivity::class, [
        'record' => $activity->getKey(),
    ])
        ->assertOk()
        ->assertSee('Fresh Co')
        ->assertDontSee('No field changes recorded');
});

it('renders the view page for a custom-field-changes activity', function (): void {
    $activity = seedActivity($this->workspaceA, $this->ownerA, [
        'event' => 'custom_field_changes',
        'description' => 'custom_field_changes',
        'properties' => ['custom_field_changes' => [[
            'code' => 'priority',
            'label' => 'Priority',
            'type' => 'select',
            'old' => ['value' => 'low', 'label' => 'Low'],
            'new' => ['value' => 'high', 'label' => 'High'],
        ]]],
    ]);

    livewire(ViewActivity::class, [
        'record' => $activity->getKey(),
    ])
        ->assertOk()
        ->assertSee('Priority')
        ->assertSee('Low')
        ->assertSee('High');
});

it('renders the view page for a deleted activity with an itemized old→new diff', function (): void {
    $activity = seedActivity($this->workspaceA, $this->ownerA, [
        'event' => 'deleted',
        'description' => 'deleted',
        'properties' => ['old' => ['name' => 'Acme Co']],
    ]);

    livewire(ViewActivity::class, [
        'record' => $activity->getKey(),
    ])
        ->assertOk()
        ->assertSee('Acme Co')
        ->assertDontSee('{"name"');
});

it('does not error when sorting by the polymorphic user column', function (): void {
    seedActivity($this->workspaceA, $this->ownerA);

    livewire(ListActivities::class)
        ->sortTable('causer.name')
        ->assertOk();
});

it('shows the subject name alongside its type on the list page', function (): void {
    $company = Company::withoutEvents(fn (): Company => Company::factory()->create(['name' => 'Acme Rockets']));
    seedActivity($this->workspaceA, $this->ownerA, ['subject_id' => $company->id]);

    livewire(ListActivities::class)
        ->assertOk()
        ->assertSee('Company: Acme Rockets');
});

it('keeps showing the subject name after the subject was soft-deleted', function (): void {
    $company = Company::withoutEvents(fn (): Company => Company::factory()->create(['name' => 'Ghost Corp']));
    seedActivity($this->workspaceA, $this->ownerA, ['subject_id' => $company->id]);
    $company->delete();

    livewire(ListActivities::class)
        ->assertOk()
        ->assertSee('Company: Ghost Corp');
});

it('shows the subject name on the view page', function (): void {
    $company = Company::withoutEvents(fn (): Company => Company::factory()->create(['name' => 'Acme Rockets']));
    $activity = seedActivity($this->workspaceA, $this->ownerA, ['subject_id' => $company->id]);

    livewire(ViewActivity::class, ['record' => $activity->getKey()])
        ->assertOk()
        ->assertSee('Company: Acme Rockets');
});

it('hydrates the workspace filter from the top-workspaces deep link query string', function (): void {
    $a = seedActivity($this->workspaceA, $this->ownerA);
    $b = seedActivity($this->workspaceB, $this->ownerB);

    Livewire\Livewire::withQueryParams(['filters' => ['workspace_id' => ['value' => $this->workspaceA->id]]])
        ->test(ListActivities::class)
        ->assertCanSeeTableRecords([$a])
        ->assertCanNotSeeTableRecords([$b]);
});
