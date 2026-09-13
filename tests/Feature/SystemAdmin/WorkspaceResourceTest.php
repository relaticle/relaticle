<?php

declare(strict_types=1);

use App\Enums\BillingStatus;
use App\Enums\Plan;
use App\Models\ActivityLog\Activity;
use App\Models\ActivityLog\Scopes\WorkspaceScope;
use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Laravel\Cashier\Subscription;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages\EditWorkspace;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages\ListWorkspaces;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages\ViewWorkspace;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\RelationManagers\ActivityRelationManager;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\RelationManagers\CompaniesRelationManager;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\RelationManagers\MembersRelationManager;
use Relaticle\SystemAdmin\Filament\Support\PivotSafeTableQuery;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(BillingStatus::class, WorkspaceResource::class, MembersRelationManager::class, CompaniesRelationManager::class, ActivityRelationManager::class, PivotSafeTableQuery::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
});

it('links workspace members to the user view page using the user key, not the pivot key', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $workspace = $owner->ownedWorkspaces()->first();

    $member = User::factory()->withPersonalWorkspace()->create();
    $workspace->users()->attach($member, ['role' => 'admin']);

    livewire(MembersRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => ViewWorkspace::class,
    ])
        ->assertSuccessful()
        ->assertSeeHtml("users/{$member->getKey()}");
});

it('links workspace companies to the company view page', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $workspace = $owner->ownedWorkspaces()->first();

    $company = Company::factory()->for($workspace)->create(['creator_id' => $owner->getKey()]);

    livewire(CompaniesRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => ViewWorkspace::class,
    ])
        ->assertSuccessful()
        ->assertSeeHtml("companies/{$company->getKey()}")
        ->assertSeeHtml("users/{$owner->getKey()}");
});

/**
 * @param  array<string, mixed>  $attributes
 */
function logWorkspaceActivity(Workspace $workspace, ?User $causer = null, array $attributes = []): Activity
{
    return Activity::withoutGlobalScope(WorkspaceScope::class)->create([
        'log_name' => 'crm',
        'description' => 'created',
        'event' => 'created',
        'subject_type' => 'company',
        'subject_id' => Company::withoutEvents(fn (): Company => Company::factory()->for($workspace)->create())->getKey(),
        'causer_type' => $causer instanceof User ? 'user' : null,
        'causer_id' => $causer?->getKey(),
        'workspace_id' => $workspace->getKey(),
        'properties' => [],
        ...$attributes,
    ]);
}

it('shows only the viewed workspace activity, which the tenant scope would otherwise hide entirely', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $workspace = $owner->ownedWorkspaces()->firstOrFail();
    $other = User::factory()->withPersonalWorkspace()->create()->ownedWorkspaces()->firstOrFail();

    $mine = logWorkspaceActivity($workspace, $owner);
    $theirs = logWorkspaceActivity($other);

    livewire(ActivityRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => ViewWorkspace::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs])
        ->assertSeeHtml("activity/{$mine->getKey()}");
});

it('reads a custom-field edit as the update it is, and names the field that moved', function (): void {
    $workspace = User::factory()->withPersonalWorkspace()->create()->ownedWorkspaces()->firstOrFail();

    logWorkspaceActivity($workspace, null, [
        'event' => 'custom_field_changes',
        'description' => 'custom_field_changes',
        'properties' => ['custom_field_changes' => [['label' => 'Deal Stage', 'old' => 'New', 'new' => 'Won']]],
    ]);
    logWorkspaceActivity($workspace, null, [
        'event' => 'updated',
        'description' => 'updated',
        'attribute_changes' => ['attributes' => ['name' => 'Acme Global'], 'old' => ['name' => 'Acme']],
    ]);

    livewire(ActivityRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => ViewWorkspace::class,
    ])
        ->assertSuccessful()
        ->assertDontSee('custom_field_changes')
        ->assertSee('Deal Stage: New → Won')
        ->assertSee('Name: Acme → Acme Global');
});

it('counts a workspace activity on the tab badge', function (): void {
    $workspace = User::factory()->withPersonalWorkspace()->create()->ownedWorkspaces()->firstOrFail();

    expect(ActivityRelationManager::getBadge($workspace, ViewWorkspace::class))->toBeNull();

    logWorkspaceActivity($workspace);

    expect(ActivityRelationManager::getBadge($workspace, ViewWorkspace::class))->toBe('1');
});

it('filters workspace activity by event', function (): void {
    $workspace = User::factory()->withPersonalWorkspace()->create()->ownedWorkspaces()->firstOrFail();

    $created = logWorkspaceActivity($workspace);
    $deleted = logWorkspaceActivity($workspace, null, ['event' => 'deleted', 'description' => 'deleted']);

    livewire(ActivityRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => ViewWorkspace::class,
    ])
        ->filterTable('event', 'deleted')
        ->assertCanSeeTableRecords([$deleted])
        ->assertCanNotSeeTableRecords([$created]);
});

it('filters workspace activity by the member who caused it', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $workspace = $owner->ownedWorkspaces()->firstOrFail();

    $member = User::factory()->withPersonalWorkspace()->create();
    $workspace->users()->attach($member, ['role' => 'admin']);

    $byOwner = logWorkspaceActivity($workspace, $owner);
    $byMember = logWorkspaceActivity($workspace, $member);

    livewire(ActivityRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => ViewWorkspace::class,
    ])
        ->filterTable('causer', $member->getKey())
        ->assertCanSeeTableRecords([$byMember])
        ->assertCanNotSeeTableRecords([$byOwner]);
});

it('deletes a workspace through the Jetstream deleter so members keep no dangling current workspace', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $workspace = $owner->ownedWorkspaces()->firstOrFail();

    $member = User::factory()->withPersonalWorkspace()->create();
    $workspace->users()->attach($member, ['role' => 'admin']);
    $member->forceFill(['current_workspace_id' => $workspace->getKey()])->save();

    livewire(EditWorkspace::class, ['record' => $workspace->getKey()])
        ->callAction('delete')
        ->assertHasNoActionErrors();

    expect(Workspace::query()->find($workspace->getKey()))->toBeNull()
        ->and($member->refresh()->current_workspace_id)->toBeNull();
});

it('deletes workspaces in bulk through the Jetstream deleter so members keep no dangling current workspace', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $workspace = $owner->ownedWorkspaces()->firstOrFail();
    $second = User::factory()->withPersonalWorkspace()->create()->ownedWorkspaces()->firstOrFail();

    $member = User::factory()->withPersonalWorkspace()->create();
    $workspace->users()->attach($member, ['role' => 'admin']);
    $member->forceFill(['current_workspace_id' => $workspace->getKey()])->save();

    livewire(ListWorkspaces::class)
        ->selectTableRecords([$workspace->getKey(), $second->getKey()])
        ->callAction([['name' => 'delete', 'context' => ['table' => true, 'bulk' => true]]])
        ->assertHasNoActionErrors();

    expect(Workspace::query()->whereKey([$workspace->getKey(), $second->getKey()])->count())->toBe(0)
        ->and($member->refresh()->current_workspace_id)->toBeNull();
});

function billingStatusWorkspace(): Workspace
{
    return User::factory()->withPersonalWorkspace()->create()->ownedWorkspaces()->firstOrFail();
}

/**
 * @return array<string, array{0: callable(Workspace): void, 1: BillingStatus}>
 */
function billingStatusArrangements(): array
{
    return [
        'a trial reads Trial, never Pro' => [
            fn (Workspace $workspace) => $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(5)])->save(),
            BillingStatus::Trialing,
        ],
        'a paid subscription reads Pro' => [
            function (Workspace $workspace): void {
                $workspace->forceFill(['plan' => Plan::Pro])->save();
                Subscription::factory()->active()->create(['workspace_id' => $workspace->getKey()]);
            },
            BillingStatus::Subscribed,
        ],
        'a lapsed subscription reads Past due, not Pro' => [
            function (Workspace $workspace): void {
                $workspace->forceFill(['plan' => Plan::Pro])->save();
                Subscription::factory()->pastDue()->create(['workspace_id' => $workspace->getKey()]);
            },
            BillingStatus::PastDue,
        ],
        'a hand-assigned plan reads Granted, not Pro' => [
            fn (Workspace $workspace) => $workspace->forceFill(['plan' => Plan::Pro])->save(),
            BillingStatus::Granted,
        ],
        'an enterprise workspace reads Enterprise' => [
            fn (Workspace $workspace) => $workspace->forceFill(['plan' => Plan::Enterprise])->save(),
            BillingStatus::Enterprise,
        ],
        'a pre-billing workspace reads Free (legacy)' => [
            fn (Workspace $workspace) => $workspace->forceFill(['hosted_free_grandfathered_at' => now()])->save(),
            BillingStatus::Grandfathered,
        ],
        'a workspace with nothing bought reads Free' => [
            fn (Workspace $workspace): null => null,
            BillingStatus::Free,
        ],
    ];
}

it('labels a workspace by why it has its plan, not by the plan alone', function (callable $arrange, BillingStatus $expected): void {
    $workspace = billingStatusWorkspace();
    $arrange($workspace);

    expect($workspace->fresh()?->billingStatus())->toBe($expected);

    livewire(ListWorkspaces::class)
        ->assertCanSeeTableRecords([$workspace])
        ->assertSee($expected->getLabel())
        ->assertSeeHtml($expected->getDescription());
})->with(billingStatusArrangements());

it('filters workspaces by the badge they show, and by no other', function (): void {
    $workspaces = [];

    foreach (billingStatusArrangements() as [$arrange, $status]) {
        $workspace = billingStatusWorkspace();
        $arrange($workspace);
        $workspaces[$status->value] = $workspace->fresh();
    }

    expect(array_keys($workspaces))
        ->toEqualCanonicalizing(array_column(BillingStatus::cases(), 'value'));

    expect(collect($workspaces)->map(fn (Workspace $workspace): string => $workspace->billingStatus()->value)->all())
        ->toBe(array_combine(array_keys($workspaces), array_keys($workspaces)));

    foreach ($workspaces as $value => $workspace) {
        livewire(ListWorkspaces::class)
            ->filterTable('billing_status', [$value])
            ->assertCanSeeTableRecords([$workspace])
            ->assertCanNotSeeTableRecords(collect($workspaces)->except($value)->values()->all());
    }
});

it('filters on the subscription a workspace bills on now, not a superseded one', function (): void {
    $workspace = billingStatusWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    Subscription::factory()->pastDue()->create([
        'workspace_id' => $workspace->getKey(),
        'created_at' => now()->subMonths(2),
        'updated_at' => now()->subMonths(2),
    ]);

    Subscription::factory()->active()->create(['workspace_id' => $workspace->getKey()]);

    expect($workspace->fresh()?->billingStatus())->toBe(BillingStatus::Subscribed);

    livewire(ListWorkspaces::class)
        ->filterTable('billing_status', [BillingStatus::Subscribed])
        ->assertCanSeeTableRecords([$workspace]);

    livewire(ListWorkspaces::class)
        ->filterTable('billing_status', [BillingStatus::PastDue])
        ->assertCanNotSeeTableRecords([$workspace]);
});

it('filters a workspace still inside its grace period as Pro, the way the badge reads it', function (): void {
    $workspace = billingStatusWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    Subscription::factory()->unpaid()->create([
        'workspace_id' => $workspace->getKey(),
        'ends_at' => now()->addDays(5),
    ]);

    expect($workspace->fresh()?->billingStatus())->toBe(BillingStatus::Subscribed);

    livewire(ListWorkspaces::class)
        ->filterTable('billing_status', [BillingStatus::Subscribed])
        ->assertCanSeeTableRecords([$workspace]);

    livewire(ListWorkspaces::class)
        ->filterTable('billing_status', [BillingStatus::PastDue])
        ->assertCanNotSeeTableRecords([$workspace]);
});

it('filters on the default subscription, ignoring a newer one of another type', function (): void {
    $workspace = billingStatusWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    Subscription::factory()->active()->create([
        'workspace_id' => $workspace->getKey(),
        'created_at' => now()->subMonths(2),
        'updated_at' => now()->subMonths(2),
    ]);

    Subscription::factory()->pastDue()->create([
        'workspace_id' => $workspace->getKey(),
        'type' => 'addon',
    ]);

    expect($workspace->fresh()?->billingStatus())->toBe(BillingStatus::Subscribed);

    livewire(ListWorkspaces::class)
        ->filterTable('billing_status', [BillingStatus::Subscribed])
        ->assertCanSeeTableRecords([$workspace]);

    livewire(ListWorkspaces::class)
        ->filterTable('billing_status', [BillingStatus::PastDue])
        ->assertCanNotSeeTableRecords([$workspace]);
});

it('prefers a live subscription over a trial that has not run out yet', function (): void {
    $workspace = billingStatusWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(5)])->save();
    Subscription::factory()->active()->create(['workspace_id' => $workspace->getKey()]);

    expect($workspace->fresh()?->billingStatus())->toBe(BillingStatus::Subscribed);
});
