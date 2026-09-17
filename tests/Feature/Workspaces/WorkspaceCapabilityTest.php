<?php

declare(strict_types=1);

use App\Enums\WorkspaceCapability;
use App\Enums\WorkspaceRole;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

mutates(WorkspaceRole::class, User::class);

test('grants the owner every capability', function (): void {
    $owner = User::factory()->withWorkspace()->create();

    foreach (WorkspaceCapability::cases() as $capability) {
        expect($owner->hasWorkspaceCapability($owner->currentWorkspace->getKey(), $capability))->toBeTrue();
    }
});

test('maps each role to its capabilities', function (WorkspaceRole $role, array $granted, array $denied): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    $user = User::factory()->create();
    $workspace->users()->attach($user, ['role' => $role->value]);
    $user = $user->fresh();

    foreach ($granted as $capability) {
        expect($user->hasWorkspaceCapability($workspace->getKey(), $capability))->toBeTrue();
    }

    foreach ($denied as $capability) {
        expect($user->hasWorkspaceCapability($workspace->getKey(), $capability))->toBeFalse();
    }
})->with([
    'admin' => [
        WorkspaceRole::Admin,
        [WorkspaceCapability::RecordsForceDelete, WorkspaceCapability::MembersManage, WorkspaceCapability::FieldsManage, WorkspaceCapability::ActivityView, WorkspaceCapability::DataExport],
        [WorkspaceCapability::MembersPromoteAdmin, WorkspaceCapability::BillingManage, WorkspaceCapability::WorkspaceManage],
    ],
    'member' => [
        WorkspaceRole::Member,
        [WorkspaceCapability::RecordsCreate, WorkspaceCapability::RecordsUpdate, WorkspaceCapability::RecordsDelete, WorkspaceCapability::DataImport, WorkspaceCapability::DataExport],
        [WorkspaceCapability::RecordsForceDelete, WorkspaceCapability::MembersManage, WorkspaceCapability::FieldsManage, WorkspaceCapability::ActivityView],
    ],
    'viewer' => [
        WorkspaceRole::Viewer,
        [WorkspaceCapability::RecordsView],
        [WorkspaceCapability::RecordsCreate, WorkspaceCapability::RecordsDelete, WorkspaceCapability::DataImport, WorkspaceCapability::DataExport],
    ],
]);

test('denies every capability on a workspace the user does not belong to', function (): void {
    $outsider = User::factory()->withWorkspace()->create();
    $other = User::factory()->withWorkspace()->create()->currentWorkspace;

    expect($outsider->hasWorkspaceCapability($other->getKey(), WorkspaceCapability::RecordsView))->toBeFalse();
});

test('grants capabilities on a workspace created after an earlier ownership check', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();

    expect($owner->hasWorkspaceCapability($owner->currentWorkspace->getKey(), WorkspaceCapability::WorkspaceManage))->toBeTrue();

    $newWorkspace = Workspace::factory()->create(['user_id' => $owner->getKey(), 'personal_workspace' => false]);

    expect($owner->hasWorkspaceCapability($newWorkspace->getKey(), WorkspaceCapability::WorkspaceManage))->toBeTrue();
});

test('keeps ownership separate per workspace on one user instance', function (bool $ownedFirst): void {
    $user = User::factory()->withWorkspace()->create();
    $owned = $user->currentWorkspace;

    $foreign = User::factory()->withWorkspace()->create()->currentWorkspace;
    $foreign->users()->attach($user, ['role' => WorkspaceRole::Viewer->value]);
    $user = $user->fresh();

    $checks = [
        [$owned->getKey(), true],
        [$foreign->getKey(), false],
    ];

    foreach ($ownedFirst ? $checks : array_reverse($checks) as [$workspaceId, $expected]) {
        expect($user->hasWorkspaceCapability($workspaceId, WorkspaceCapability::BillingManage))->toBe($expected);
    }
})->with([
    'owned workspace checked first' => [true],
    'foreign workspace checked first' => [false],
]);

test('renders a 25-row companies list as a member without an ownership EXISTS query per row', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);
    $member->switchWorkspace($workspace);

    Company::factory()->count(25)->create(['workspace_id' => $workspace->id]);

    $this->actingAs($member);
    Filament::setTenant($workspace);

    DB::enableQueryLog();

    livewire(ListCompanies::class);

    $queryCount = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($queryCount)->toBeLessThanOrEqual(15);
});
