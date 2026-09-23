<?php

declare(strict_types=1);

use App\Actions\People\CreatePeople;
use App\Actions\Workspace\CreateWorkspaceInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;

function runRenameEditorRoleToMemberMigration(): void
{
    $migration = require database_path('migrations/2026_09_18_000000_rename_editor_role_to_member.php');
    $migration->up();
}

test('renames stored editor roles to member across every column that holds one', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $member = User::factory()->create();
    $admin = User::factory()->create();

    $workspace->users()->attach($member, ['role' => 'editor']);
    $workspace->users()->attach($admin, ['role' => 'admin']);

    DB::table('workspaces')->where('id', $workspace->getKey())->update(['invite_link_default_role' => 'editor']);

    DB::table('workspace_invitations')->insert([
        'id' => (string) Str::ulid(),
        'workspace_id' => $workspace->getKey(),
        'email' => 'invited@example.test',
        'role' => 'editor',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    runRenameEditorRoleToMemberMigration();

    expect(DB::table('workspace_user')->where('user_id', $member->getKey())->value('role'))->toBe('member')
        ->and(DB::table('workspace_user')->where('user_id', $admin->getKey())->value('role'))->toBe('admin')
        ->and(DB::table('workspaces')->where('id', $workspace->getKey())->value('invite_link_default_role'))->toBe('member')
        ->and(DB::table('workspace_invitations')->where('email', 'invited@example.test')->value('role'))->toBe('member');
});

test('moves the invite link default so a new workspace stores member', function (): void {
    runRenameEditorRoleToMemberMigration();

    $default = DB::selectOne(
        "select column_default from information_schema.columns where table_name = 'workspaces' and column_name = 'invite_link_default_role'"
    );

    expect($default->column_default)->toContain('member');
});

test('retires a pending invitation proposal and leaves other pending proposals alone', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    $invitationProposal = PendingAction::query()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $owner->getKey(),
        'action_class' => CreateWorkspaceInvitation::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'workspace_invitations',
        'action_data' => ['records' => [['email' => 'invited@example.test', 'role' => 'editor']]],
        'display_data' => ['title' => 'Invite member'],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $peopleProposal = PendingAction::query()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $owner->getKey(),
        'action_class' => CreatePeople::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'people',
        'action_data' => ['records' => [['name' => 'Dana Whitfield']]],
        'display_data' => ['title' => 'Create Person'],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    runRenameEditorRoleToMemberMigration();

    $invitationProposal->refresh();
    $peopleProposal->refresh();

    expect($invitationProposal->status)->toBe(PendingActionStatus::Superseded)
        ->and($invitationProposal->resolved_at)->not->toBeNull()
        ->and($peopleProposal->status)->toBe(PendingActionStatus::Pending)
        ->and($peopleProposal->resolved_at)->toBeNull();
});
