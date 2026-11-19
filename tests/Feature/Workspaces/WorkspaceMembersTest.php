<?php

declare(strict_types=1);

use App\Actions\Jetstream\UpdateWorkspaceMemberRole;
use App\Enums\WorkspaceRole;
use App\Features\Billing;
use App\Features\EmailIntegration;
use App\Livewire\App\Workspaces\InviteWorkspaceMembers;
use App\Livewire\App\Workspaces\WorkspaceMembers;
use App\Models\User;
use App\Support\Workspaces\RoleOptions;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;

mutates(WorkspaceMembers::class, RoleOptions::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

test('the owner appears in the members list even though they have no pivot row', function (): void {
    expect($this->workspace->users()->count())->toBe(0);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertSee($this->owner->email)
        ->assertSee(__('workspaces.roles.owner.label'));
});

test('a pending invitation shares the roster with the joined members', function (): void {
    $this->workspace->workspaceInvitations()->create([
        'email' => 'pending@example.test',
        'role' => 'member',
        'expires_at' => now()->addDays(5),
    ]);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertSee('pending@example.test')
        ->assertSee(__('workspaces.table.invite_pending'))
        ->assertSee($this->owner->email);
});

test('the owner row offers no leave action', function (): void {
    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertTableActionHidden('leaveWorkspace', $this->owner->id);
});

test('the owner row offers no remove action', function (): void {
    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertTableActionHidden('removeWorkspaceMember', $this->owner->id);
});

test('the owner row offers no role change action', function (): void {
    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertTableActionHidden('updateWorkspaceRole', $this->owner->id);
});

test('a member can be removed', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction(TestAction::make('removeWorkspaceMember')->table($member->id));

    expect($member->fresh()->belongsToWorkspace($this->workspace))->toBeFalse();
});

test('an admin cannot promote another member to admin', function (): void {
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);

    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);

    $this->actingAs($admin);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction(TestAction::make('updateWorkspaceRole')->table($member->id), ['role' => WorkspaceRole::Admin->value])
        ->assertHasActionErrors(['role']);

    expect($member->fresh()->membershipRole($this->workspace))->toBe(WorkspaceRole::Member->value);
});

test('an admin cannot demote a peer admin', function (): void {
    $adminA = User::factory()->create();
    $this->workspace->users()->attach($adminA, ['role' => WorkspaceRole::Admin->value]);

    $adminB = User::factory()->create();
    $this->workspace->users()->attach($adminB, ['role' => WorkspaceRole::Admin->value]);

    $this->actingAs($adminA);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertTableActionHidden('updateWorkspaceRole', $adminB->id);

    expect($adminB->fresh()->membershipRole($this->workspace))->toBe(WorkspaceRole::Admin->value);
});

test('the owner can change a member role', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction(TestAction::make('updateWorkspaceRole')->table($member->id), ['role' => WorkspaceRole::Viewer->value])
        ->assertHasNoActionErrors();

    expect($member->fresh()->membershipRole($this->workspace))->toBe(WorkspaceRole::Viewer->value);
});

test('multiple people can be invited in one submission', function (): void {
    Mail::fake();

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => "one@example.test\ntwo@example.test",
            'role' => WorkspaceRole::Member->value,
        ]);

    expect($this->workspace->fresh()->workspaceInvitations->pluck('email')->all())
        ->toEqualCanonicalizing(['one@example.test', 'two@example.test']);
});

test('invitePeople rejects an admin role for a non-owner actor', function (): void {
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);
    $this->actingAs($admin);

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'nope@example.test',
            'role' => WorkspaceRole::Admin->value,
        ])
        ->assertHasActionErrors();

    expect($this->workspace->fresh()->workspaceInvitations)->toHaveCount(0);
});

test('the members list skips a membership row whose user no longer exists', function (): void {
    Schema::table('workspace_user', function (Blueprint $table): void {
        $table->dropForeign(['user_id']);
    });

    $member = User::factory()->create();
    $deletedUser = User::factory()->create();

    $this->workspace->users()->attach([
        $member->id => ['role' => WorkspaceRole::Member->value],
        $deletedUser->id => ['role' => WorkspaceRole::Member->value],
    ]);

    $deletedEmail = $deletedUser->email;
    $deletedUser->delete();

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertSee($member->email)
        ->assertDontSee($deletedEmail);
});

test('a crafted payload above the batch cap is rejected server-side', function (): void {
    $emails = collect(range(1, 11))->map(fn (int $i): string => "batch{$i}@example.test")->implode("\n");

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', ['emails' => $emails, 'role' => WorkspaceRole::Member->value])
        ->assertHasActionErrors(['emails']);

    expect($this->workspace->fresh()->workspaceInvitations)->toHaveCount(0);
});

test('a submission exactly at the batch cap succeeds', function (): void {
    Mail::fake();

    $emails = collect(range(1, 10))->map(fn (int $i): string => "atcap{$i}@example.test")->implode("\n");

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', ['emails' => $emails, 'role' => WorkspaceRole::Member->value])
        ->assertHasNoActionErrors();

    expect($this->workspace->fresh()->workspaceInvitations)->toHaveCount(10);
});

test('cumulative invite volume beyond the window cap is throttled, not just the call count', function (): void {
    Mail::fake();

    $firstBatch = collect(range(1, 10))->map(fn (int $i): string => "first{$i}@example.test")->implode("\n");
    $secondBatch = collect(range(1, 10))->map(fn (int $i): string => "second{$i}@example.test")->implode("\n");

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', ['emails' => $firstBatch, 'role' => WorkspaceRole::Member->value]);

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', ['emails' => $secondBatch, 'role' => WorkspaceRole::Member->value]);

    expect($this->workspace->fresh()->workspaceInvitations)->toHaveCount(20);

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction('invitePeople', [
            'emails' => 'onemore@example.test',
            'role' => WorkspaceRole::Member->value,
        ]);

    expect($this->workspace->fresh()->workspaceInvitations)->toHaveCount(20);
});

test('a member role must be a role the app actually registers', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);

    expect(fn () => resolve(UpdateWorkspaceMemberRole::class)->update($this->owner, $this->workspace, (string) $member->getKey(), 'superuser'))
        ->toThrow(ValidationException::class);

    expect($member->fresh()->membershipRole($this->workspace->fresh()))->toBe(WorkspaceRole::Member->value);
});

test('the members list is searchable by name and by email', function (): void {
    $needle = User::factory()->create(['name' => 'Zoltan Searchme', 'email' => 'searchme@example.test']);
    $this->workspace->users()->attach($needle, ['role' => WorkspaceRole::Member->value]);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertCanSeeTableRecords([$needle, $this->owner])
        ->searchTable('Zoltan')
        ->assertCanSeeTableRecords([$needle])
        ->assertCanNotSeeTableRecords([$this->owner])
        ->searchTable('searchme@example.test')
        ->assertCanSeeTableRecords([$needle])
        ->assertCanNotSeeTableRecords([$this->owner]);
});

test('a search term containing SQL wildcards is matched literally', function (): void {
    $literal = User::factory()->create(['name' => 'Ann_Lee', 'email' => 'ann-underscore@example.test']);
    $decoy = User::factory()->create(['name' => 'AnnXLee', 'email' => 'ann-decoy@example.test']);

    $this->workspace->users()->attach($literal, ['role' => WorkspaceRole::Member->value]);
    $this->workspace->users()->attach($decoy, ['role' => WorkspaceRole::Member->value]);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->searchTable('Ann_Lee')
        ->assertCanSeeTableRecords([$literal])
        ->assertCanNotSeeTableRecords([$decoy]);
});

test('a search that matches nobody explains itself instead of showing a blank table', function (): void {
    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->searchTable('nobody-by-that-name')
        ->assertSee(__('workspaces.table.no_results.heading'))
        ->assertDontSee($this->owner->email);
});

test('the members list paginates rather than rendering every member at once', function (): void {
    $members = User::factory()->count(30)->create();

    foreach ($members as $member) {
        $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);
    }

    $page = livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->instance()
        ->getTableRecords();

    expect($page)->toHaveCount(25)
        ->and($page->total())->toBe(31);
});

test('an admin sees no remove action on another admins row', function (): void {
    $admin = User::factory()->create();
    $peer = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);
    $this->workspace->users()->attach($peer, ['role' => WorkspaceRole::Admin->value]);

    $this->actingAs($admin);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertTableActionHidden('removeWorkspaceMember', $peer->id);
});

test('an admin still manages a non admin member', function (): void {
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);

    $this->actingAs($admin);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertTableActionVisible('updateWorkspaceRole', $member->id)
        ->assertTableActionVisible('removeWorkspaceMember', $member->id);
});

test('the owner still manages an admin', function (): void {
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertTableActionVisible('updateWorkspaceRole', $admin->id)
        ->assertTableActionVisible('removeWorkspaceMember', $admin->id);
});

test('shows a hint for every role option in the change role modal', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->mountAction(TestAction::make('updateWorkspaceRole')->table($member->id))
        ->assertSchemaComponentExists('role', checkComponentUsing: function (Radio $component): bool {
            $descriptions = $component->getDescriptions();

            return $descriptions[WorkspaceRole::Admin->value] === __('workspaces.roles.admin.description')
                && $descriptions[WorkspaceRole::Member->value] === __('workspaces.roles.member.description')
                && $descriptions[WorkspaceRole::Viewer->value] === __('workspaces.roles.viewer.description');
        });
});

test('the change role modal offers a way to compare roles rendered from the capability map', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);

    $component = livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->mountAction([
            TestAction::make('updateWorkspaceRole')->table($member->id),
            TestAction::make('compareRoles')->schemaComponent('role'),
        ]);

    $content = (string) $component->instance()->getMountedAction()->getModalContent();

    expect($content)
        ->toContain(__('workspaces.roles.owner.label'))
        ->toContain(__('workspaces.capabilities.fields.manage.label'));
});

test('the compare roles modal lists the manage billing row only while billing is on', function (bool $billingIsOn): void {
    Feature::define(Billing::class, $billingIsOn);
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);

    $component = livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->mountAction([
            TestAction::make('updateWorkspaceRole')->table($member->id),
            TestAction::make('compareRoles')->schemaComponent('role'),
        ]);

    $content = (string) $component->instance()->getMountedAction()->getModalContent();

    expect(str_contains($content, __('workspaces.capabilities.billing.manage.label')))->toBe($billingIsOn)
        ->and($content)->toContain(__('workspaces.capabilities.fields.manage.label'));
})->with(['billing on' => true, 'billing off' => false]);

test('the compare roles modal links the roles guide on the documentation public url', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);

    $component = livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->mountAction([
            TestAction::make('updateWorkspaceRole')->table($member->id),
            TestAction::make('compareRoles')->schemaComponent('role'),
        ]);

    $helpAction = $component->instance()->getMountedAction()->getExtraModalFooterActions()['readRolesHelp'];

    expect($helpAction->getUrl())->toBe(rtrim((string) config('documentation.public_url'), '/').'/help/workspace/manage-members-and-roles');
});

test('removing a member warns that their mailboxes disconnect when email is on', function (bool $emailIsOn): void {
    Feature::define(EmailIntegration::class, $emailIsOn);
    $member = User::factory()->create(['name' => 'Dana Whitfield']);
    $this->workspace->users()->attach($member, ['role' => 'member']);

    $warning = __('workspaces.modals.remove_workspace_member.mailbox_notice', ['name' => 'Dana Whitfield']);

    $modal = livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->mountAction(TestAction::make('removeWorkspaceMember')->table($member->id));

    $emailIsOn
        ? $modal->assertMountedActionModalSee($warning)
        : $modal->assertMountedActionModalDontSee($warning)->assertMountedActionModalSee(__('filament-actions::modal.confirmation'));
})->with(['email on' => true, 'email off' => false]);

test('leaving a workspace warns that your mailboxes disconnect when email is on', function (bool $emailIsOn): void {
    Feature::define(EmailIntegration::class, $emailIsOn);
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => 'member']);
    $member->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    $this->actingAs($member);

    $warning = __('workspaces.modals.leave_workspace.mailbox_notice');

    $modal = livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->mountAction(TestAction::make('leaveWorkspace')->table($member->id));

    $emailIsOn
        ? $modal->assertMountedActionModalSee($warning)
        : $modal->assertMountedActionModalDontSee($warning)->assertMountedActionModalSee(__('workspaces.modals.leave_workspace.notice'));
})->with(['email on' => true, 'email off' => false]);
