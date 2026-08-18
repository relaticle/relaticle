# Team Invitations and Membership Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close eight confirmed dead ends in the team invitation flow, make the role system real (Admin can manage members, Viewer is read-only), and replace the three stacked settings widgets with one unified members list.

**Architecture:** Invitations move from host-bound signed URLs to opaque SHA-256 tokens with an explicit confirm step and five named accept states. `TeamPolicy` stops answering `ownsTeam` for member operations. The Members page collapses to one Livewire component whose Filament table reads a `fromSub()` union of members and pending invitations. Writes continue to route through `app/Actions/Jetstream/`.

**Tech Stack:** Laravel 12, PHP 8.4, Filament v4, Livewire v3, PostgreSQL, Pest v4, Jetstream (teams).

**Spec:** `docs/superpowers/specs/2026-08-18-team-invitation-membership-design.md`

## Global Constraints

- **PostgreSQL only.** No SQLite/MySQL compatibility layers, driver checks, or conditional SQL.
- **Migrations have `up()` only.** Never write `down()`.
- **All writes go through actions** in `app/Actions/<Domain>/`. `EloquentWriteOutsideActionRule` (PHPStan) fails analysis on Eloquent writes in controllers, Livewire components, or Filament classes.
- **All user-facing strings wrapped in `__()`.** `HardcodedUserFacingStringRule` and `HardcodedStaticPropertyRule` fail analysis otherwise. Add keys to `lang/en/teams.php`.
- **100% type coverage.** Every parameter, return type, and closure signature explicitly typed.
- **Tests live in `tests/Feature/Teams/`.** Extend the existing file covering the same scope; do not create a new file where one already covers the subject. No `tests/Unit/`.
- **`tests/Pest.php` already binds `TestCase` + `LazilyRefreshDatabase`** for Feature. Do not repeat `uses(...)`.
- Role string values: `admin`, `editor`, `viewer`. Owner is **not** a role — ownership is `teams.user_id`.
- Invitation expiry: `config('jetstream.invitation_expiry_days')`, default 7.
- Token: `Str::random(40)` raw, stored as `hash('sha256', $raw)`.

**Before every commit, in order:**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run     # if it suggests changes, apply with: vendor/bin/rector
vendor/bin/phpstan analyse
composer test:type-coverage
```

Do not add new PHPStan ignores.

---

### Task 1: Add the Viewer role

**Files:**
- Modify: `app/Enums/TeamRole.php`
- Modify: `app/Providers/JetstreamServiceProvider.php:70-81`
- Modify: `lang/en/teams.php:115-122`
- Test: `tests/Feature/Teams/UpdateTeamMemberRoleTest.php`

**Interfaces:**
- Produces: `TeamRole::Viewer` (value `'viewer'`); Jetstream role `viewer` registered with abilities `['read']`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Teams/UpdateTeamMemberRoleTest.php`:

```php
test('viewer is a registered team role with only read ability', function (): void {
    $role = Laravel\Jetstream\Jetstream::findRole(App\Enums\TeamRole::Viewer->value);

    expect($role)->not->toBeNull()
        ->and($role->key)->toBe('viewer')
        ->and($role->permissions)->toBe(['read']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="viewer is a registered team role"`
Expected: FAIL — `TeamRole::Viewer` does not exist.

- [ ] **Step 3: Add the enum case**

In `app/Enums/TeamRole.php`:

```php
enum TeamRole: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';
}
```

- [ ] **Step 4: Register the Jetstream role**

In `app/Providers/JetstreamServiceProvider.php`, after the `Editor` registration:

```php
Jetstream::role(TeamRole::Viewer->value, 'Viewer', [
    'read',
])->description(__('teams.roles.viewer.description'));
```

- [ ] **Step 5: Add the translation**

In `lang/en/teams.php`, inside `'roles' => [`:

```php
'viewer' => [
    'description' => 'Viewer users can read records but cannot create, update, or delete.',
],
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact --filter="viewer is a registered team role"`
Expected: PASS

- [ ] **Step 7: Sweep SystemAdmin for unhandled matches**

`packages/SystemAdmin` is excluded from PHPStan, and an enum case added without this sweep already caused a production `UnhandledMatchError`.

Run: `grep -rn "TeamRole\|'admin'\|'editor'\|pivot.role\|roleName" packages/SystemAdmin/src`
Expected: no output. If there is output, add a `TeamRole::Viewer` arm to every `match` it reveals.

- [ ] **Step 8: Run quality gates and commit**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/rector --dry-run && vendor/bin/phpstan analyse && composer test:type-coverage
git add app/Enums/TeamRole.php app/Providers/JetstreamServiceProvider.php lang/en/teams.php tests/Feature/Teams/UpdateTeamMemberRoleTest.php
git commit -m "feat: add viewer team role"
```

---

### Task 2: Make Viewer read-only across all record types

**Files:**
- Modify: `app/Models/User.php` (add `isViewerOnTeamId`)
- Create: `app/Policies/Concerns/ChecksTeamWriteAccess.php`
- Modify: `app/Policies/CompanyPolicy.php`, `PeoplePolicy.php`, `TaskPolicy.php`, `NotePolicy.php`, `OpportunityPolicy.php`
- Test: `tests/Feature/Teams/ViewerRoleTest.php` (new — no existing file covers Viewer)

**Interfaces:**
- Consumes: `TeamRole::Viewer` from Task 1.
- Produces: `User::isViewerOnTeamId(?string $teamId): bool` — true only when the user's membership role on that team is `viewer`. Always false for the team owner. `ChecksTeamWriteAccess::canWriteInTeam(User, ?string): bool` and `::canCreateInCurrentTeam(User): bool`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Teams/ViewerRoleTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;

mutates(User::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withTeam()->create();
    $this->team = $this->owner->currentTeam;

    $this->viewer = User::factory()->create();
    $this->team->users()->attach($this->viewer, ['role' => TeamRole::Viewer->value]);
    $this->viewer->switchTeam($this->team);

    $this->editor = User::factory()->create();
    $this->team->users()->attach($this->editor, ['role' => TeamRole::Editor->value]);
    $this->editor->switchTeam($this->team);
});

test('viewer cannot create update or delete companies', function (): void {
    $this->actingAs($this->viewer);
    Filament::setTenant($this->team);

    $company = Company::factory()->create(['team_id' => $this->team->id]);

    expect($this->viewer->can('create', Company::class))->toBeFalse()
        ->and($this->viewer->can('update', $company))->toBeFalse()
        ->and($this->viewer->can('delete', $company))->toBeFalse()
        ->and($this->viewer->can('view', $company))->toBeTrue();
});

test('editor keeps write access to companies', function (): void {
    $this->actingAs($this->editor);
    Filament::setTenant($this->team);

    $company = Company::factory()->create(['team_id' => $this->team->id]);

    expect($this->editor->can('create', Company::class))->toBeTrue()
        ->and($this->editor->can('update', $company))->toBeTrue();
});

test('owner is never treated as a viewer', function (): void {
    expect($this->owner->isViewerOnTeamId($this->team->id))->toBeFalse();
});

test('viewer is read-only through the API too', function (): void {
    $token = $this->viewer->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/companies', ['name' => 'Blocked Co'])
        ->assertForbidden();
});
```

The API case matters because the policies are the only thing standing between a Viewer and a write on the non-panel surfaces. If this passes without extra work, the policy path is confirmed to cover API, MCP, and chat tools alike — which is the assumption the spec makes.

Check the API route prefix and payload shape against `routes/api.php` before running; adjust the URL if the project versions it differently.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ViewerRoleTest`
Expected: FAIL — `isViewerOnTeamId` does not exist.

- [ ] **Step 3: Add the User helper**

In `app/Models/User.php`, next to `hasTeamRoleForTeamId`:

```php
/**
 * Whether the user's membership role on the given team is Viewer.
 *
 * The owner is never a viewer — ownership outranks the pivot role, and
 * `ownedTeams` is checked first so an owner row carrying a stale pivot
 * value cannot lock them out of their own workspace.
 */
public function isViewerOnTeamId(?string $teamId): bool
{
    if ($teamId === null) {
        return false;
    }

    $this->loadMissing('ownedTeams');

    if (in_array($teamId, array_map(strval(...), $this->ownedTeams->modelKeys()), true)) {
        return false;
    }

    return $this->hasTeamRoleForTeamId($teamId, TeamRole::Viewer->value);
}
```

Import `App\Enums\TeamRole`.

- [ ] **Step 4: Create the shared policy concern**

Create `app/Policies/Concerns/ChecksTeamWriteAccess.php`:

```php
<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\User;

trait ChecksTeamWriteAccess
{
    private function canWriteInTeam(User $user, ?string $teamId): bool
    {
        return $user->belongsToTeamId($teamId) && ! $user->isViewerOnTeamId($teamId);
    }

    private function canCreateInCurrentTeam(User $user): bool
    {
        $team = $user->currentTeam;

        if ($team === null) {
            return false;
        }

        return $user->hasVerifiedEmail() && ! $user->isViewerOnTeamId($team->id);
    }
}
```

- [ ] **Step 5: Apply to CompanyPolicy**

In `app/Policies/CompanyPolicy.php`, add `use ChecksTeamWriteAccess;` alongside `HandlesAuthorization`, then replace the write abilities. `viewAny` and `view` are unchanged — Viewer reads.

```php
public function create(User $user): bool
{
    return $this->canCreateInCurrentTeam($user);
}

public function update(User $user, Company $company): bool
{
    return $this->canWriteInTeam($user, $company->team_id);
}

public function delete(User $user, Company $company): bool
{
    return $this->canWriteInTeam($user, $company->team_id);
}

public function deleteAny(User $user): bool
{
    return $this->canCreateInCurrentTeam($user);
}

public function restore(User $user, Company $company): bool
{
    return $this->canWriteInTeam($user, $company->team_id);
}

public function restoreAny(User $user): bool
{
    return $this->canCreateInCurrentTeam($user);
}
```

- [ ] **Step 6: Apply the identical shape to the other four policies**

`PeoplePolicy`, `TaskPolicy`, `NotePolicy`, `OpportunityPolicy` have the same method set. Apply the same replacement, substituting the model type and its `team_id` accessor. Two of them (`PeoplePolicy`, `TaskPolicy`) already use `hasTeamRoleForTeamId` on `forceDelete` — leave `forceDelete` and `forceDeleteAny` exactly as they are; Viewer already fails the `admin` check there.

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --compact --filter=ViewerRoleTest`
Expected: PASS

- [ ] **Step 8: Run the full Teams suite for regressions**

Run: `php artisan test --compact tests/Feature/Teams`
Expected: PASS. Editors and admins must be unaffected — if an existing test now fails, the concern is denying more than Viewer.

- [ ] **Step 9: Run quality gates and commit**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/rector --dry-run && vendor/bin/phpstan analyse && composer test:type-coverage
git add app/Models/User.php app/Policies tests/Feature/Teams/ViewerRoleTest.php
git commit -m "feat: make viewer role read-only across all record types"
```

---

### Task 3: Let Admins manage members

**Files:**
- Modify: `app/Policies/TeamPolicy.php`
- Modify: `app/Filament/Pages/Team/Members.php:39-45`
- Test: `tests/Feature/Teams/InviteTeamMemberTest.php`

**Interfaces:**
- Produces: `TeamPolicy::manageMembers(User, Team): bool` — true for owner and Admin. `addTeamMember`, `updateTeamMember`, `removeTeamMember` all delegate to it. `TeamPolicy::promoteToAdmin(User, Team): bool` — owner only.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Teams/InviteTeamMemberTest.php`:

```php
test('admin can manage members but cannot promote to admin', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    $admin = User::factory()->create();
    $team->users()->attach($admin, ['role' => App\Enums\TeamRole::Admin->value]);

    expect($admin->can('manageMembers', $team))->toBeTrue()
        ->and($admin->can('addTeamMember', $team))->toBeTrue()
        ->and($admin->can('removeTeamMember', $team))->toBeTrue()
        ->and($admin->can('promoteToAdmin', $team))->toBeFalse()
        ->and($admin->can('update', $team))->toBeFalse();
});

test('editor cannot manage members', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    $editor = User::factory()->create();
    $team->users()->attach($editor, ['role' => App\Enums\TeamRole::Editor->value]);

    expect($editor->can('manageMembers', $team))->toBeFalse()
        ->and($editor->can('addTeamMember', $team))->toBeFalse();
});

test('owner can promote to admin', function (): void {
    $owner = User::factory()->withTeam()->create();

    expect($owner->can('promoteToAdmin', $owner->currentTeam))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="admin can manage members"`
Expected: FAIL — ability `manageMembers` is not defined, and `addTeamMember` returns false for a non-owner.

- [ ] **Step 3: Rewrite the member abilities in TeamPolicy**

In `app/Policies/TeamPolicy.php`:

```php
/**
 * Owner and Admin may invite, revoke, and change member roles.
 * Renaming, deleting, billing, and custom fields stay owner-only.
 */
public function manageMembers(User $user, Team $team): bool
{
    return $user->ownsTeam($team)
        || $user->hasTeamRoleForTeamId($team->id, TeamRole::Admin->value);
}

/**
 * Granting or revoking Admin is the owner's alone, so an Admin cannot
 * escalate a peer or themselves.
 */
public function promoteToAdmin(User $user, Team $team): bool
{
    return $user->ownsTeam($team);
}

public function addTeamMember(User $user, Team $team): bool
{
    return $this->manageMembers($user, $team);
}

public function updateTeamMember(User $user, Team $team): bool
{
    return $this->manageMembers($user, $team);
}

public function removeTeamMember(User $user, Team $team): bool
{
    return $this->manageMembers($user, $team);
}
```

Import `App\Enums\TeamRole`. Leave `update`, `delete`, `restore`, `forceDelete` on `ownsTeam`.

Note: `hasTeamRoleForTeamId` returns true for the owner via its `ownedTeams` short-circuit, so the `ownsTeam` disjunct is belt-and-braces for teams reached without a loaded membership.

- [ ] **Step 4: Open the Members page to Admins**

In `app/Filament/Pages/Team/Members.php`, `canAccess()` currently calls `can('update', $tenant)`, which is owner-only:

```php
public static function canAccess(): bool
{
    /** @var Team $tenant */
    $tenant = Filament::getTenant();

    return auth()->user()?->can('manageMembers', $tenant) === true;
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=InviteTeamMemberTest`
Expected: PASS

- [ ] **Step 6: Verify the settings tab strip follows**

`HasWorkspaceSettingsNavigation` gates the Members tab on `Members::canAccess()`, so the tab appears for Admins automatically.

Run: `php artisan test --compact tests/Feature/Teams/WorkspaceSettingsNavigationTest.php`
Expected: PASS

- [ ] **Step 7: Run quality gates and commit**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/rector --dry-run && vendor/bin/phpstan analyse && composer test:type-coverage
git add app/Policies/TeamPolicy.php app/Filament/Pages/Team/Members.php tests/Feature/Teams/InviteTeamMemberTest.php
git commit -m "feat: allow admins to manage team members"
```

---

### Task 4: Invitation tokens, inviter, and join-link default role

**Files:**
- Create: `database/migrations/2026_08_18_100000_add_token_and_inviter_to_team_invitations.php`
- Modify: `app/Models/TeamInvitation.php`
- Modify: `app/Models/Team.php`
- Modify: `database/factories/TeamInvitationFactory.php`
- Test: `tests/Feature/Teams/TeamModelTest.php`

**Interfaces:**
- Produces: `TeamInvitation::issueToken(): string` — mints a raw 40-char secret, sets `token` to its SHA-256 and `expires_at` to now + configured days, returns the raw secret (caller persists). `TeamInvitation::findByRawToken(string $raw): ?TeamInvitation`. `Team::$invite_link_default_role` (string, default `'editor'`).

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Teams/TeamModelTest.php`:

```php
test('issueToken stores a hash and returns the raw secret', function (): void {
    $team = App\Models\User::factory()->withTeam()->create()->currentTeam;

    $invitation = $team->teamInvitations()->make(['email' => 'x@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    expect($raw)->toHaveLength(40)
        ->and($invitation->token)->toBe(hash('sha256', $raw))
        ->and($invitation->token)->not->toBe($raw)
        ->and($invitation->expires_at->isFuture())->toBeTrue();

    expect(App\Models\TeamInvitation::findByRawToken($raw)?->id)->toBe($invitation->id);
    expect(App\Models\TeamInvitation::findByRawToken('wrong'))->toBeNull();
});

test('teams default their join link to the editor role', function (): void {
    $team = App\Models\User::factory()->withTeam()->create()->currentTeam;

    expect($team->invite_link_default_role)->toBe(App\Enums\TeamRole::Editor->value);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="issueToken stores a hash"`
Expected: FAIL — method and columns do not exist.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_08_18_100000_add_token_and_inviter_to_team_invitations.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_invitations', function (Blueprint $table): void {
            $table->foreignUlid('inviter_id')->nullable()->after('team_id')
                ->constrained('users')->nullOnDelete();
            $table->string('token', 64)->nullable()->unique()->after('role');
        });

        Schema::table('teams', function (Blueprint $table): void {
            $table->string('invite_link_default_role')->default(TeamRole::Editor->value);
        });
    }
};
```

`token` is nullable because invitations created before this migration have none; they continue to work through the legacy signed route (Task 7).

- [ ] **Step 4: Run the migration**

Run: `php artisan migrate`
Expected: both tables altered without error.

- [ ] **Step 5: Extend the TeamInvitation model**

In `app/Models/TeamInvitation.php`, add `'inviter_id'` and `'token'` to the `#[Fillable]` attribute, then:

```php
/**
 * Mint a fresh raw token, store its hash and a renewed expiry window on the
 * model (the caller persists), and return the raw token for delivery by mail.
 * Single source of truth for the token and expiry rules shared by the invite
 * and resend paths.
 */
public function issueToken(): string
{
    $rawToken = Str::random(40);

    $this->token = hash('sha256', $rawToken);
    $this->expires_at = now()->addDays((int) config('jetstream.invitation_expiry_days', 7));

    return $rawToken;
}

public static function findByRawToken(string $rawToken): ?self
{
    return self::query()->where('token', hash('sha256', $rawToken))->first();
}

/**
 * @return BelongsTo<User, $this>
 */
public function inviter(): BelongsTo
{
    return $this->belongsTo(User::class, 'inviter_id');
}
```

Import `Illuminate\Support\Str` and `App\Models\User`.

- [ ] **Step 6: Add the Team column to fillable and casts**

In `app/Models/Team.php`, add `'invite_link_default_role'` to the `#[Fillable]` list and the `@property` docblock as `@property string $invite_link_default_role`.

- [ ] **Step 7: Update the factory**

In `database/factories/TeamInvitationFactory.php`, add a state so tests can mint tokens:

```php
public function withToken(string &$rawToken = ''): static
{
    return $this->state(function (array $attributes) use (&$rawToken): array {
        $rawToken = Str::random(40);

        return ['token' => hash('sha256', $rawToken)];
    });
}
```

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test --compact --filter=TeamModelTest`
Expected: PASS

- [ ] **Step 9: Run quality gates and commit**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/rector --dry-run && vendor/bin/phpstan analyse && composer test:type-coverage
git add database/migrations app/Models/TeamInvitation.php app/Models/Team.php database/factories/TeamInvitationFactory.php tests/Feature/Teams/TeamModelTest.php
git commit -m "feat: add invitation tokens, inviter, and join-link default role"
```

---

### Task 5: Mint tokens and record the inviter when inviting and resending

**Files:**
- Modify: `app/Actions/Jetstream/InviteTeamMember.php:29-47`
- Modify: `app/Actions/Jetstream/ResendTeamInvitation.php`
- Test: `tests/Feature/Teams/InviteTeamMemberTest.php`, `tests/Feature/Teams/ManageTeamInvitationsTest.php`

**Interfaces:**
- Consumes: `TeamInvitation::issueToken()` from Task 4.
- Produces: every new invitation carries a non-null `token` and `inviter_id`. Resending re-issues both the token and the expiry window, so a legacy null-token row migrates onto the new path on its first resend.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Teams/InviteTeamMemberTest.php`:

```php
test('inviting records the inviter and mints a token', function (): void {
    livewire(AddTeamMember::class, ['team' => $this->team])
        ->fillForm(['email' => 'new@example.test', 'role' => 'editor'])
        ->call('addTeamMember', $this->team);

    $invitation = $this->team->fresh()->teamInvitations->first();

    expect($invitation->inviter_id)->toBe($this->user->id)
        ->and($invitation->token)->not->toBeNull();
});
```

Append to `tests/Feature/Teams/ManageTeamInvitationsTest.php`:

```php
test('resending re-issues the token and extends expiry', function (): void {
    $invitation = $this->team->teamInvitations()->create([
        'email' => 'legacy@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDay(),
    ]);

    expect($invitation->token)->toBeNull();

    resolve(App\Actions\Jetstream\ResendTeamInvitation::class)->resend($invitation->fresh());

    $invitation->refresh();

    expect($invitation->token)->not->toBeNull()
        ->and($invitation->expires_at->isAfter(now()->addDays(6)))->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="records the inviter|re-issues the token"`
Expected: FAIL — `inviter_id` and `token` are null.

- [ ] **Step 3: Update InviteTeamMember**

In `app/Actions/Jetstream/InviteTeamMember.php`, replace the creation block:

```php
$invitation = $team->teamInvitations()->make([
    'email' => Str::lower($email),
    'role' => $role,
    'inviter_id' => $user->id,
]);

$rawToken = $invitation->issueToken();
$invitation->save();

Mail::to($invitation->email)->send(new TeamInvitation($invitation));
```

`issueToken()` sets `expires_at`, so the `$expiryDays` local and the explicit `expires_at` assignment both go. Import `Illuminate\Support\Str`.

**Keep the existing `Laravel\Jetstream\Mail\TeamInvitation` mailable in this task.** `$rawToken` is minted and persisted here but not yet delivered; Task 6 introduces `App\Mail\TeamInvitationMail` and swaps both call sites over. Splitting it this way keeps each task independently green rather than leaving this one referencing a class that does not exist yet.

Emails are lowercased on the way in so the unique index on `(team_id, email)` cannot hold `Bob@x.com` and `bob@x.com` as two rows.

- [ ] **Step 4: Update ResendTeamInvitation**

```php
public function resend(TeamInvitation $invitation): void
{
    $rawToken = $invitation->issueToken();
    $invitation->save();

    Mail::to($invitation->email)->send(new TeamInvitation($invitation));
}
```

As above, `$rawToken` is persisted here but delivered from Task 6 onward.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter="records the inviter|re-issues the token"`
Expected: PASS

- [ ] **Step 6: Run quality gates and commit**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/rector --dry-run && vendor/bin/phpstan analyse && composer test:type-coverage
git add app/Actions/Jetstream tests/Feature/Teams
git commit -m "feat: record inviter and mint token on invite and resend"
```

---

### Task 6: Invitation email with inviter, role, and accept URL

**Files:**
- Create: `app/Mail/TeamInvitationMail.php`
- Modify: `resources/views/emails/team-invitation.blade.php`
- Modify: `lang/en/teams.php`
- Test: `tests/Feature/Teams/InviteTeamMemberTest.php`

**Interfaces:**
- Consumes: `TeamInvitation::issueToken()` (Task 4), route `team-invitations.token.accept` (Task 7).
- Produces: `TeamInvitationMail::__construct(TeamInvitation $invitation, string $rawToken)`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Teams/InviteTeamMemberTest.php`:

```php
test('invitation email names the inviter and the role', function (): void {
    $this->user->update(['name' => 'Ana Reyes']);

    livewire(AddTeamMember::class, ['team' => $this->team])
        ->fillForm(['email' => 'new@example.test', 'role' => 'editor'])
        ->call('addTeamMember', $this->team);

    Mail::assertSent(App\Mail\TeamInvitationMail::class, function ($mail): bool {
        return $mail->hasTo('new@example.test')
            && str_contains($mail->envelope()->subject, 'Ana Reyes')
            && str_contains($mail->envelope()->subject, $this->team->name);
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="names the inviter"`
Expected: FAIL — `App\Mail\TeamInvitationMail` does not exist.

- [ ] **Step 3: Create the mailable**

```bash
php artisan make:mail TeamInvitationMail --no-interaction
```

Then in `app/Mail/TeamInvitationMail.php`:

```php
final class TeamInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly TeamInvitation $invitation,
        public readonly string $rawToken,
    ) {}

    public function envelope(): Envelope
    {
        $inviter = $this->invitation->inviter?->name;
        $team = $this->invitation->team->name;

        return new Envelope(
            subject: $inviter === null
                ? __('teams.mail.invitation.subject_without_inviter', ['team' => $team])
                : __('teams.mail.invitation.subject', ['inviter' => $inviter, 'team' => $team]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.team-invitation',
            with: [
                'acceptUrl' => route('team-invitations.token.accept', ['token' => $this->rawToken]),
                'inviterName' => $this->invitation->inviter?->name,
                'teamName' => $this->invitation->team->name,
                'roleName' => Jetstream::findRole($this->invitation->role)?->name,
            ],
        );
    }
}
```

The `inviter === null` branch covers rows created before Task 4's migration.

- [ ] **Step 4: Update the Blade template**

In `resources/views/emails/team-invitation.blade.php`:

```blade
@component('mail::message')
@if($inviterName)
{{ __('teams.mail.invitation.line_with_inviter', ['inviter' => $inviterName, 'team' => $teamName, 'role' => $roleName]) }}
@else
{{ __('teams.mail.invitation.line', ['team' => $teamName, 'role' => $roleName]) }}
@endif

@component('mail::button', ['url' => $acceptUrl])
{{ __('teams.mail.invitation.action') }}
@endcomponent

@if($invitation->expires_at)
{{ __('teams.mail.invitation.expiry', ['expiry' => $invitation->expires_at->diffForHumans()]) }}
@endif

{{ __('teams.mail.invitation.ignore') }}
@endcomponent
```

- [ ] **Step 5: Add the translations**

In `lang/en/teams.php`, add a `'mail'` block:

```php
'mail' => [
    'invitation' => [
        'subject' => ':inviter invited you to :team on Relaticle',
        'subject_without_inviter' => 'You\'ve been invited to join :team on Relaticle',
        'line_with_inviter' => ':inviter has invited you to join the :team workspace on Relaticle as a :role.',
        'line' => 'You\'ve been invited to join the :team workspace on Relaticle as a :role.',
        'action' => 'Accept invitation',
        'expiry' => 'This invitation expires :expiry.',
        'ignore' => 'If you weren\'t expecting this, you can safely ignore this email.',
    ],
],
```

- [ ] **Step 6: Point both actions at the new mailable**

In `InviteTeamMember` and `ResendTeamInvitation`, replace `Laravel\Jetstream\Mail\TeamInvitation` with `App\Mail\TeamInvitationMail`, passing `$rawToken` as the second argument.

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --compact --filter="names the inviter"`
Expected: PASS

- [ ] **Step 8: Run quality gates and commit**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/rector --dry-run && vendor/bin/phpstan analyse && composer test:type-coverage
git add app/Mail resources/views/emails lang/en/teams.php app/Actions/Jetstream tests/Feature/Teams/InviteTeamMemberTest.php
git commit -m "feat: name the inviter and role in the invitation email"
```

---

### Task 7: Accept flow — confirm page and five states

**Files:**
- Modify: `app/Http/Controllers/AcceptTeamInvitationController.php`
- Create: `app/Http/Middleware/NoReferrer.php`
- Create: `resources/views/teams/accept-invitation.blade.php`
- Modify: `routes/web.php:67-69`
- Modify: `bootstrap/app.php:121-123` (middleware alias)
- Modify: `lang/en/teams.php`
- Test: `tests/Feature/Teams/AcceptTeamInvitationTest.php`

**Interfaces:**
- Consumes: `TeamInvitation::findByRawToken()` (Task 4).
- Produces: routes `team-invitations.token.accept` (GET `/invitations/{token}`), `team-invitations.token.join` (POST `/invitations/{token}`), and the legacy pair `team-invitations.accept` / `team-invitations.join`. Controller methods `show()` and `store()`. View states: `ready`, `wrong-account`, `expired`, `already-member`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Teams/AcceptTeamInvitationTest.php`:

```php
test('a GET on the accept link never joins the team', function (): void {
    $invitation = $this->team->teamInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    $this->actingAs($invitee)
        ->get(route('team-invitations.token.accept', ['token' => $raw]))
        ->assertOk()
        ->assertSee($this->team->name);

    expect($invitee->fresh()->belongsToTeam($this->team))->toBeFalse();
});

test('a POST joins the team', function (): void {
    $invitation = $this->team->teamInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    $this->actingAs($invitee)
        ->post(route('team-invitations.token.join', ['token' => $raw]))
        ->assertRedirect();

    expect($invitee->fresh()->belongsToTeam($this->team))->toBeTrue()
        ->and(App\Models\TeamInvitation::query()->whereKey($invitation->id)->exists())->toBeFalse();
});

test('a mismatched email gets the wrong-account screen not a 403', function (): void {
    $invitation = $this->team->teamInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $other = User::factory()->create(['email' => 'someone-else@example.test']);

    $this->actingAs($other)
        ->get(route('team-invitations.token.accept', ['token' => $raw]))
        ->assertOk()
        ->assertSee('invitee@example.test')
        ->assertSee('someone-else@example.test');
});

test('email matching is case insensitive', function (): void {
    $invitation = $this->team->teamInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'INVITEE@example.test']);

    $this->actingAs($invitee)
        ->post(route('team-invitations.token.join', ['token' => $raw]))
        ->assertRedirect();

    expect($invitee->fresh()->belongsToTeam($this->team))->toBeTrue();
});

test('an expired invitation shows the expired state', function (): void {
    $invitation = $this->team->teamInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->expires_at = now()->subDay();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    $this->actingAs($invitee)
        ->get(route('team-invitations.token.accept', ['token' => $raw]))
        ->assertOk()
        ->assertSee(__('teams.accept.expired.heading'));
});

test('two concurrent accepts attach exactly one membership', function (): void {
    $invitation = $this->team->teamInvitations()->make(['email' => 'invitee@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    $this->actingAs($invitee)->post(route('team-invitations.token.join', ['token' => $raw]));
    $this->actingAs($invitee)->post(route('team-invitations.token.join', ['token' => $raw]));

    expect($this->team->users()->where('users.id', $invitee->id)->count())->toBe(1);
});

test('a legacy signed link still resolves and its POST still joins', function (): void {
    $invitation = $this->team->teamInvitations()->create([
        'email' => 'legacy@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(3),
    ]);

    $invitee = User::factory()->create(['email' => 'legacy@example.test']);

    $showUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);
    $this->actingAs($invitee)->get($showUrl)->assertOk();

    expect($invitee->fresh()->belongsToTeam($this->team))->toBeFalse();

    $joinUrl = URL::signedRoute('team-invitations.join', ['invitation' => $invitation]);
    $this->actingAs($invitee)->post($joinUrl)->assertRedirect();

    expect($invitee->fresh()->belongsToTeam($this->team))->toBeTrue();
});
```

Import `Illuminate\Support\Facades\URL`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=AcceptTeamInvitationTest`
Expected: FAIL — the token routes do not exist and GET currently joins.

- [ ] **Step 3: Create the no-referrer middleware**

Create `app/Http/Middleware/NoReferrer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The raw invitation token is the only secret in the URL, so it must not ride
 * along in the Referer header of any link the accept page renders.
 */
final class NoReferrer
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
```

Register the alias in `bootstrap/app.php` next to `'signed'`:

```php
$middleware->alias([
    'signed' => ValidateSignature::class,
    'no-referrer' => NoReferrer::class,
]);
```

- [ ] **Step 4: Rewrite the controller**

`app/Http/Controllers/AcceptTeamInvitationController.php` becomes a two-method controller. Resolution differs (raw token vs signed key) but every downstream decision is shared.

```php
final readonly class AcceptTeamInvitationController
{
    public function show(Request $request, string $token): View|RedirectResponse
    {
        return $this->render($request, $this->resolve($token, $request));
    }

    public function store(Request $request, string $token): RedirectResponse|View
    {
        $invitation = $this->resolve($token, $request);
        $user = $this->user($request);

        if ($invitation === null || $invitation->isExpired()) {
            return $this->render($request, $invitation);
        }

        if (! $this->emailMatches($user, $invitation)) {
            return $this->render($request, $invitation);
        }

        abort_if($user->isScheduledForDeletion(), 403, __('teams.accept.account_deleting'));

        $team = $invitation->team;

        DB::transaction(function () use ($invitation, $user, $team): void {
            $locked = TeamInvitation::query()->lockForUpdate()->find($invitation->id);

            if ($locked === null || $locked->isExpired()) {
                return;
            }

            if (! $user->belongsToTeam($team)) {
                /** @var User $owner */
                $owner = $team->owner;

                resolve(AddsTeamMembers::class)->add($owner, $team, $locked->email, $locked->role);
            }

            $locked->delete();
        });

        $user->unsetRelation('teams');
        $user->switchTeam($team);

        return redirect(config('fortify.home'))
            ->banner(__('teams.accept.joined', ['team' => $team->name])); // @phpstan-ignore method.notFound
    }

    private function resolve(string $token, Request $request): ?TeamInvitation
    {
        if ($request->routeIs('team-invitations.token.*')) {
            return TeamInvitation::findByRawToken($token);
        }

        return TeamInvitation::query()->whereKey($token)->first();
    }

    private function emailMatches(User $user, TeamInvitation $invitation): bool
    {
        return Str::lower($user->email) === Str::lower($invitation->email);
    }

    private function render(Request $request, ?TeamInvitation $invitation): View|RedirectResponse
    {
        $user = $this->user($request);

        if ($invitation === null || $invitation->isExpired()) {
            Log::warning('Invalid or expired invitation accessed', [
                'invitation_id' => $invitation?->id,
            ]);

            return view('teams.accept-invitation', ['state' => 'expired']);
        }

        if (! $this->emailMatches($user, $invitation)) {
            Log::warning('Invitation email mismatch', [
                'invitation_id' => $invitation->id,
                'user_id' => $user->id,
            ]);

            return view('teams.accept-invitation', [
                'state' => 'wrong-account',
                'invitedEmail' => $invitation->email,
                'currentEmail' => $user->email,
                'teamName' => $invitation->team->name,
            ]);
        }

        if ($user->belongsToTeam($invitation->team)) {
            $user->switchTeam($invitation->team);

            return redirect(config('fortify.home'))
                ->banner(__('teams.accept.already_member', ['team' => $invitation->team->name])); // @phpstan-ignore method.notFound
        }

        return view('teams.accept-invitation', [
            'state' => 'ready',
            'teamName' => $invitation->team->name,
            'inviterName' => $invitation->inviter?->name,
            'roleName' => Jetstream::findRole($invitation->role)?->name,
            'joinUrl' => $request->routeIs('team-invitations.token.*')
                ? route('team-invitations.token.join', ['token' => $request->route('token')])
                : URL::signedRoute('team-invitations.join', ['invitation' => $invitation]),
        ]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
```

The legacy `show` renders a form posting to a signed `team-invitations.join` URL, because legacy rows have `token = null` and cannot address the token route.

- [ ] **Step 5: Register the routes**

In `routes/web.php`, replace the single accept route:

```php
Route::middleware(['signed', 'auth', 'verified', AuthenticateSession::class])->group(function (): void {
    Route::get('/team-invitations/{invitation}', [AcceptTeamInvitationController::class, 'show'])
        ->name('team-invitations.accept');

    Route::post('/team-invitations/{invitation}', [AcceptTeamInvitationController::class, 'store'])
        ->name('team-invitations.join');
});

Route::middleware(['auth', 'verified', 'no-referrer', AuthenticateSession::class])->group(function (): void {
    Route::get('/invitations/{token}', [AcceptTeamInvitationController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{40}')
        ->name('team-invitations.token.accept');

    Route::post('/invitations/{token}', [AcceptTeamInvitationController::class, 'store'])
        ->where('token', '[A-Za-z0-9]{40}')
        ->name('team-invitations.token.join');
});
```

- [ ] **Step 6: Create the accept view**

Create `resources/views/teams/accept-invitation.blade.php` with a branch per state. Model the markup on the existing `resources/views/teams/invitation-expired.blade.php` so it inherits the same guest layout.

```blade
<x-guest-layout>
    @if ($state === 'ready')
        <h1>{{ __('teams.accept.ready.heading', ['team' => $teamName]) }}</h1>
        <p>
            @if ($inviterName)
                {{ __('teams.accept.ready.body_with_inviter', ['inviter' => $inviterName, 'team' => $teamName, 'role' => $roleName]) }}
            @else
                {{ __('teams.accept.ready.body', ['team' => $teamName, 'role' => $roleName]) }}
            @endif
        </p>
        <form method="POST" action="{{ $joinUrl }}">
            @csrf
            <button type="submit">{{ __('teams.accept.ready.action', ['team' => $teamName]) }}</button>
        </form>
        <a href="{{ config('fortify.home') }}">{{ __('teams.accept.ready.decline') }}</a>
    @elseif ($state === 'wrong-account')
        <h1>{{ __('teams.accept.wrong_account.heading') }}</h1>
        <p>{{ __('teams.accept.wrong_account.body', ['invited' => $invitedEmail, 'current' => $currentEmail]) }}</p>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit">{{ __('teams.accept.wrong_account.switch') }}</button>
        </form>
        <a href="{{ config('fortify.home') }}">{{ __('teams.accept.wrong_account.stay') }}</a>
    @else
        <h1>{{ __('teams.accept.expired.heading') }}</h1>
        <p>{{ __('teams.accept.expired.body') }}</p>
        <a href="{{ config('fortify.home') }}">{{ __('teams.accept.expired.action') }}</a>
    @endif
</x-guest-layout>
```

Signing out on the wrong-account screen must return the user to the invitation, so the logout form carries the current URL — add `<input type="hidden" name="redirect_to" value="{{ url()->current() }}">` only if the app's logout route honours it; otherwise leave the plain logout and let the login page's invitation banner (Task 8) carry them back.

- [ ] **Step 7: Add the translations**

In `lang/en/teams.php`:

```php
'accept' => [
    'joined' => 'You have joined the :team workspace.',
    'already_member' => 'You are already a member of :team.',
    'account_deleting' => 'You cannot accept invitations while your account is scheduled for deletion.',
    'ready' => [
        'heading' => 'Join :team',
        'body_with_inviter' => ':inviter invited you to join :team as a :role.',
        'body' => 'You have been invited to join :team as a :role.',
        'action' => 'Join :team',
        'decline' => 'Not now',
    ],
    'wrong_account' => [
        'heading' => 'This invitation is for a different account',
        'body' => 'This invitation was sent to :invited, but you are signed in as :current.',
        'switch' => 'Sign out and switch account',
        'stay' => 'Go to my workspace',
    ],
    'expired' => [
        'heading' => 'Invitation no longer valid',
        'body' => 'This invitation has expired or has already been accepted.',
        'action' => 'Go to my workspace',
    ],
],
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test --compact --filter=AcceptTeamInvitationTest`
Expected: PASS

- [ ] **Step 9: Delete the now-unused expired view**

`resources/views/teams/invitation-expired.blade.php` is superseded by the `expired` state. Confirm nothing references it, then remove it.

```bash
grep -rn "invitation-expired" app resources routes tests
rm resources/views/teams/invitation-expired.blade.php
```

If the grep returns any hit, leave the file and fix the reference instead.

- [ ] **Step 10: Run quality gates and commit**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/rector --dry-run && vendor/bin/phpstan analyse && composer test:type-coverage
git add app/Http routes/web.php bootstrap/app.php resources/views/teams lang/en/teams.php tests/Feature/Teams/AcceptTeamInvitationTest.php
git commit -m "feat: require explicit confirmation to accept a team invitation"
```

---

### Task 8: Guest routing and invitation banners for token links

**Files:**
- Modify: `app/Concerns/DetectsTeamInvitation.php:14-38, 68-92`
- Modify: `bootstrap/app.php:129-141`
- Test: `tests/Feature/Teams/InviteLinkBannerTest.php`

**Interfaces:**
- Consumes: `TeamInvitation::findByRawToken()` (Task 4), route names from Task 7.
- Produces: `DetectsTeamInvitation::getTeamInvitationFromSession()` resolves both `/team-invitations/{ulid}` and `/invitations/{rawToken}` intended URLs.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Teams/InviteLinkBannerTest.php`:

```php
test('a token invitation link shows the team banner on login', function (): void {
    $team = User::factory()->withTeam()->create()->currentTeam;

    $invitation = $team->teamInvitations()->make(['email' => 'guest@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    session(['url.intended' => route('team-invitations.token.accept', ['token' => $raw])]);

    $this->get(Filament::getLoginUrl())
        ->assertOk()
        ->assertSee($team->name);
});

test('a guest with no account is sent to register', function (): void {
    $team = User::factory()->withTeam()->create()->currentTeam;

    $invitation = $team->teamInvitations()->make(['email' => 'brand-new@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $this->get(route('team-invitations.token.accept', ['token' => $raw]))
        ->assertRedirect(Filament::getRegistrationUrl());
});

test('a guest who already has an account is sent to login', function (): void {
    $team = User::factory()->withTeam()->create()->currentTeam;
    User::factory()->create(['email' => 'existing@example.test']);

    $invitation = $team->teamInvitations()->make(['email' => 'existing@example.test', 'role' => 'editor']);
    $raw = $invitation->issueToken();
    $invitation->save();

    $this->get(route('team-invitations.token.accept', ['token' => $raw]))
        ->assertRedirect(Filament::getLoginUrl());
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=InviteLinkBannerTest`
Expected: FAIL — the trait finds no invitation for the new segment, and guests fall through to `route('login')`.

- [ ] **Step 3: Teach the trait about token links**

In `app/Concerns/DetectsTeamInvitation.php`:

```php
protected function getTeamInvitationFromSession(): ?TeamInvitation
{
    $legacySegment = $this->getIntendedUrlSegmentAfter('team-invitations');

    if ($legacySegment !== null) {
        return TeamInvitation::query()->whereKey($legacySegment)->first();
    }

    $token = $this->getIntendedUrlSegmentAfter('invitations');

    if ($token === null) {
        return null;
    }

    return TeamInvitation::findByRawToken($token);
}
```

The two needles do not collide: `str_contains('/team-invitations/X', '/invitations/')` is false because the segment is preceded by `team-`, and `array_search('invitations', ['team-invitations', 'X'])` finds nothing. The legacy branch is checked first regardless.

- [ ] **Step 4: Extend the guest redirect**

In `bootstrap/app.php`, `redirectGuestsTo` currently only recognises `team-invitations.accept` and resolves by primary key. Generalise the lookup:

```php
$middleware->redirectGuestsTo(function (Request $request): string {
    $invitation = match (true) {
        $request->routeIs('team-invitations.accept') => TeamInvitation::query()
            ->whereKey($request->route('invitation'))
            ->first(),
        $request->routeIs('team-invitations.token.accept') => TeamInvitation::findByRawToken(
            (string) $request->route('token')
        ),
        default => null,
    };

    if ($invitation === null) {
        return route('login');
    }

    return User::query()->where('email', $invitation->email)->exists()
        ? Filament::getLoginUrl()
        : Filament::getRegistrationUrl();
});
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=InviteLinkBannerTest`
Expected: PASS

- [ ] **Step 6: Run quality gates and commit**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/rector --dry-run && vendor/bin/phpstan analyse && composer test:type-coverage
git add app/Concerns/DetectsTeamInvitation.php bootstrap/app.php tests/Feature/Teams/InviteLinkBannerTest.php
git commit -m "feat: route and banner token invitation links for guests"
```

---

### Task 9: Surface pending invitations after independent signup

**Files:**
- Create: `app/Livewire/App/Teams/PendingInvitationsForUser.php`
- Create: `resources/views/livewire/app/teams/pending-invitations-for-user.blade.php`
- Create: `app/Actions/Jetstream/DeclineTeamInvitation.php`
- Modify: the app panel's home page render hook in `app/Providers/Filament/AppPanelProvider.php`
- Modify: `lang/en/teams.php`
- Test: `tests/Feature/Teams/PendingInvitationsForUserTest.php` (new)

**Interfaces:**
- Consumes: accept logic from Task 7.
- Produces: `PendingInvitationsForUser` Livewire component with `accept(string $invitationId): void` and `decline(string $invitationId): void`. `DeclineTeamInvitation::decline(User $user, TeamInvitation $invitation): void`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Teams/PendingInvitationsForUserTest.php`:

```php
<?php

declare(strict_types=1);

use App\Livewire\App\Teams\PendingInvitationsForUser;
use App\Models\TeamInvitation;
use App\Models\User;

mutates(PendingInvitationsForUser::class);

test('an independently registered invitee sees their pending invitation', function (): void {
    $team = User::factory()->withTeam()->create()->currentTeam;
    $team->teamInvitations()->create([
        'email' => 'later@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $invitee = User::factory()->create(['email' => 'later@example.test']);
    $this->actingAs($invitee);

    livewire(PendingInvitationsForUser::class)->assertSee($team->name);
});

test('accepting from the card joins the team', function (): void {
    $team = User::factory()->withTeam()->create()->currentTeam;
    $invitation = $team->teamInvitations()->create([
        'email' => 'later@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $invitee = User::factory()->create(['email' => 'later@example.test']);
    $this->actingAs($invitee);

    livewire(PendingInvitationsForUser::class)->call('accept', $invitation->id);

    expect($invitee->fresh()->belongsToTeam($team))->toBeTrue();
});

test('declining revokes the invitation without joining', function (): void {
    $team = User::factory()->withTeam()->create()->currentTeam;
    $invitation = $team->teamInvitations()->create([
        'email' => 'later@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $invitee = User::factory()->create(['email' => 'later@example.test']);
    $this->actingAs($invitee);

    livewire(PendingInvitationsForUser::class)->call('decline', $invitation->id);

    expect(TeamInvitation::query()->whereKey($invitation->id)->exists())->toBeFalse()
        ->and($invitee->fresh()->belongsToTeam($team))->toBeFalse();
});

test('another users invitation is invisible and cannot be accepted', function (): void {
    $team = User::factory()->withTeam()->create()->currentTeam;
    $invitation = $team->teamInvitations()->create([
        'email' => 'someone@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $stranger = User::factory()->withTeam()->create();
    $this->actingAs($stranger);

    livewire(PendingInvitationsForUser::class)
        ->assertDontSee($team->name)
        ->call('accept', $invitation->id);

    expect($stranger->fresh()->belongsToTeam($team))->toBeFalse();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=PendingInvitationsForUserTest`
Expected: FAIL — component does not exist.

- [ ] **Step 3: Create the decline action**

Create `app/Actions/Jetstream/DeclineTeamInvitation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Str;

final readonly class DeclineTeamInvitation
{
    public function decline(User $user, TeamInvitation $invitation): void
    {
        abort_unless(
            Str::lower($user->email) === Str::lower($invitation->email),
            403,
        );

        $invitation->delete();
    }
}
```

- [ ] **Step 4: Create the Livewire component**

`app/Livewire/App/Teams/PendingInvitationsForUser.php` queries by the signed-in user's email only — the record id from the client is never trusted as the sole filter.

```php
final class PendingInvitationsForUser extends BaseLivewireComponent
{
    /** @return Collection<int, TeamInvitation> */
    public function getInvitationsProperty(): Collection
    {
        return TeamInvitation::query()
            ->with('team')
            ->whereRaw('lower(email) = ?', [Str::lower($this->authUser()->email)])
            ->where('expires_at', '>', now())
            ->get();
    }

    public function accept(string $invitationId): void
    {
        $invitation = $this->ownedInvitation($invitationId);

        if ($invitation === null) {
            return;
        }

        $user = $this->authUser();
        $team = $invitation->team;

        DB::transaction(function () use ($invitation, $user, $team): void {
            $locked = TeamInvitation::query()->lockForUpdate()->find($invitation->id);

            if ($locked === null || $locked->isExpired()) {
                return;
            }

            if (! $user->belongsToTeam($team)) {
                /** @var User $owner */
                $owner = $team->owner;

                resolve(AddsTeamMembers::class)->add($owner, $team, $locked->email, $locked->role);
            }

            $locked->delete();
        });

        $user->unsetRelation('teams');
        $user->switchTeam($team);

        $this->redirect(Filament::getHomeUrl());
    }

    public function decline(string $invitationId): void
    {
        $invitation = $this->ownedInvitation($invitationId);

        if ($invitation === null) {
            return;
        }

        resolve(DeclineTeamInvitation::class)->decline($this->authUser(), $invitation);

        $this->sendNotification(__('teams.pending_for_user.declined'));
    }

    private function ownedInvitation(string $invitationId): ?TeamInvitation
    {
        return $this->invitations->firstWhere('id', $invitationId);
    }

    public function render(): View
    {
        return view('livewire.app.teams.pending-invitations-for-user');
    }
}
```

- [ ] **Step 5: Create the view**

`resources/views/livewire/app/teams/pending-invitations-for-user.blade.php` renders nothing when the collection is empty, so it is inert for the overwhelming majority of page loads:

```blade
<div>
    @foreach ($this->invitations as $invitation)
        <x-filament::section>
            <x-slot name="heading">
                {{ __('teams.pending_for_user.heading', ['team' => $invitation->team->name]) }}
            </x-slot>

            <div class="flex gap-3">
                <x-filament::button wire:click="accept('{{ $invitation->id }}')">
                    {{ __('teams.pending_for_user.accept') }}
                </x-filament::button>
                <x-filament::button color="gray" wire:click="decline('{{ $invitation->id }}')">
                    {{ __('teams.pending_for_user.decline') }}
                </x-filament::button>
            </div>
        </x-filament::section>
    @endforeach
</div>
```

- [ ] **Step 6: Mount it on the panel home**

In `app/Providers/Filament/AppPanelProvider.php`, register the component via a render hook so it appears above page content:

```php
->renderHook(
    PanelsRenderHook::PAGE_START,
    fn (): string => Blade::render('@livewire(\'app.teams.pending-invitations-for-user\')'),
)
```

Check the file for an existing `renderHook` block and add to it rather than introducing a second one.

- [ ] **Step 7: Add the translations**

```php
'pending_for_user' => [
    'heading' => 'You have been invited to join :team',
    'accept' => 'Join workspace',
    'decline' => 'Decline',
    'declined' => 'Invitation declined.',
],
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test --compact --filter=PendingInvitationsForUserTest`
Expected: PASS

- [ ] **Step 9: Run quality gates and commit**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/rector --dry-run && vendor/bin/phpstan analyse && composer test:type-coverage
git add app/Livewire app/Actions/Jetstream/DeclineTeamInvitation.php resources/views/livewire app/Providers/Filament/AppPanelProvider.php lang/en/teams.php tests/Feature/Teams/PendingInvitationsForUserTest.php
git commit -m "feat: surface pending invitations to independently registered users"
```

---

### Task 10: TeamPerson — the unified members and invitations query

**Files:**
- Create: `app/Models/TeamPerson.php`
- Test: `tests/Feature/Teams/TeamPersonTest.php` (new)

**Interfaces:**
- Produces: `TeamPerson::forTeam(Team $team): Builder<TeamPerson>`. Row attributes: `id` (string, `member:<bigint>` or `invite:<ulid>`), `user_id` (?string), `name` (?string), `email` (string), `role` (string), `status` (`member`|`invited`), `happened_at` (datetime), `expires_at` (?datetime), `source_id` (string — the bare pivot id or invitation ulid).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Teams/TeamPersonTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\TeamPerson;
use App\Models\User;

mutates(TeamPerson::class);

test('the unified query returns members and invitations with distinct keys', function (): void {
    $owner = User::factory()->withTeam()->create(['name' => 'Ana Reyes']);
    $team = $owner->currentTeam;

    $team->teamInvitations()->create([
        'email' => 'pending@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $rows = TeamPerson::forTeam($team)->get();

    expect($rows)->toHaveCount(2);

    $member = $rows->firstWhere('status', 'member');
    $invited = $rows->firstWhere('status', 'invited');

    expect($member->name)->toBe('Ana Reyes')
        ->and($member->email)->toBe($owner->email)
        ->and($member->user_id)->toBe($owner->id)
        ->and($member->id)->toStartWith('member:')
        ->and($invited->name)->toBeNull()
        ->and($invited->email)->toBe('pending@example.test')
        ->and($invited->user_id)->toBeNull()
        ->and($invited->id)->toStartWith('invite:');
});

test('the unified query is scoped to one team', function (): void {
    $team = User::factory()->withTeam()->create()->currentTeam;
    $otherTeam = User::factory()->withTeam()->create()->currentTeam;

    $otherTeam->teamInvitations()->create([
        'email' => 'elsewhere@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    expect(TeamPerson::forTeam($team)->pluck('email'))
        ->not->toContain('elsewhere@example.test');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=TeamPersonTest`
Expected: FAIL — `App\Models\TeamPerson` does not exist.

- [ ] **Step 3: Create the model**

`team_user.id` is `bigint` and `team_invitations.id` is a ULID `character`, so the union casts both to text and prefixes them. Postgres also requires explicit casts on the `null` columns for a `UNION ALL` to resolve types.

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Read-only projection of a team's people: current members and pending
 * invitations in one list. Never written through — every mutation goes to the
 * actions in App\Actions\Jetstream.
 *
 * @property string $id
 * @property ?string $user_id
 * @property ?string $name
 * @property string $email
 * @property string $role
 * @property string $status
 * @property ?string $profile_photo_path
 * @property string $source_id
 */
final class TeamPerson extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $table = 'team_people';

    /**
     * @return Builder<self>
     */
    public static function forTeam(Team $team): Builder
    {
        $members = DB::table('team_user')
            ->join('users', 'users.id', '=', 'team_user.user_id')
            ->where('team_user.team_id', $team->id)
            ->select([
                DB::raw("'member:' || team_user.id::text as id"),
                DB::raw('team_user.id::text as source_id'),
                'users.id as user_id',
                'users.name as name',
                'users.email as email',
                'users.profile_photo_path as profile_photo_path',
                'team_user.role as role',
                DB::raw("'member' as status"),
                'team_user.created_at as happened_at',
                DB::raw('null::timestamp as expires_at'),
            ]);

        $invitations = DB::table('team_invitations')
            ->where('team_id', $team->id)
            ->select([
                DB::raw("'invite:' || id::text as id"),
                DB::raw('id::text as source_id'),
                DB::raw('null::char(26) as user_id'),
                DB::raw('null::varchar as name'),
                'email',
                DB::raw('null::varchar as profile_photo_path'),
                'role',
                DB::raw("'invited' as status"),
                'created_at as happened_at',
                'expires_at',
            ]);

        return self::query()->fromSub($members->unionAll($invitations), 'team_people');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'happened_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=TeamPersonTest`
Expected: PASS. If Postgres rejects a cast, adjust the `null::<type>` to match the concrete column type reported by the error — do not remove the cast.

- [ ] **Step 5: Run quality gates and commit**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/rector --dry-run && vendor/bin/phpstan analyse && composer test:type-coverage
git add app/Models/TeamPerson.php tests/Feature/Teams/TeamPersonTest.php
git commit -m "feat: add unified team people projection"
```

---

### Task 11: ManageMembers — one component, one table

**Files:**
- Create: `app/Livewire/App/Teams/ManageMembers.php`
- Create: `resources/views/livewire/app/teams/manage-members.blade.php`
- Modify: `app/Filament/Pages/Team/Members.php:62-75`
- Modify: `lang/en/teams.php`
- Delete: `app/Livewire/App/Teams/TeamMembers.php`, `PendingTeamInvitations.php`, `AddTeamMember.php` and their three views
- Test: `tests/Feature/Teams/ManageMembersTest.php` (new), and update the existing tests that drive the deleted components

**Interfaces:**
- Consumes: `TeamPerson::forTeam()` (Task 10), `TeamPolicy::manageMembers` / `promoteToAdmin` (Task 3).
- Produces: `ManageMembers` Livewire component, mounted with `['team' => Team]`. Table actions keep their existing names so the four test files that drive them need no rename: `updateTeamRole`, `removeTeamMember`, `leaveTeam`, `resendTeamInvitation`, `copyInviteLink`, `revokeTeamInvitation`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Teams/ManageMembersTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Livewire\App\Teams\ManageMembers;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

mutates(ManageMembers::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withTeam()->create();
    $this->team = $this->owner->currentTeam;
    $this->actingAs($this->owner);
    Filament::setTenant($this->team);
});

test('members and pending invitations appear in one list', function (): void {
    $this->team->teamInvitations()->create([
        'email' => 'pending@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    livewire(ManageMembers::class, ['team' => $this->team])
        ->assertSee($this->owner->email)
        ->assertSee('pending@example.test');
});

test('the owner row offers no leave action', function (): void {
    livewire(ManageMembers::class, ['team' => $this->team])
        ->assertTableActionHidden('leaveTeam', 'member:'.$this->team->users()->first()->membership->id);
});

test('a member can be removed', function (): void {
    $member = User::factory()->create();
    $this->team->users()->attach($member, ['role' => TeamRole::Editor->value]);

    $key = 'member:'.$this->team->users()->where('users.id', $member->id)->first()->membership->id;

    livewire(ManageMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('removeTeamMember')->table($key));

    expect($member->fresh()->belongsToTeam($this->team))->toBeFalse();
});

test('an admin cannot promote another member to admin', function (): void {
    $admin = User::factory()->create();
    $this->team->users()->attach($admin, ['role' => TeamRole::Admin->value]);

    $member = User::factory()->create();
    $this->team->users()->attach($member, ['role' => TeamRole::Editor->value]);

    $this->actingAs($admin);

    $key = 'member:'.$this->team->users()->where('users.id', $member->id)->first()->membership->id;

    livewire(ManageMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('updateTeamRole')->table($key), ['role' => TeamRole::Admin->value])
        ->assertHasActionErrors(['role']);

    expect($member->fresh()->teamRole($this->team)->key)->toBe(TeamRole::Editor->value);
});

test('a pending invitation can be revoked', function (): void {
    $invitation = $this->team->teamInvitations()->create([
        'email' => 'pending@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    livewire(ManageMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('revokeTeamInvitation')->table('invite:'.$invitation->id));

    expect($this->team->fresh()->teamInvitations)->toHaveCount(0);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=ManageMembersTest`
Expected: FAIL — component does not exist.

- [ ] **Step 3: Build the component**

`app/Livewire/App/Teams/ManageMembers.php`. Every action re-authorizes; visibility alone is not a security boundary.

```php
final class ManageMembers extends BaseLivewireComponent implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;

    public Team $team;

    public function mount(Team $team): void
    {
        $this->team = $team;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => TeamPerson::forTeam($this->team))
            ->searchable()
            // The replaced components both set paginated(false); restoring
            // pagination is defect A7's fix and must not be dropped.
            ->paginated([10, 25, 50])
            ->defaultSort('happened_at')
            ->heading(__('teams.table.heading'))
            ->description(fn (): string => __('teams.table.counts', [
                'members' => $this->team->users()->count(),
                'pending' => $this->team->teamInvitations()->count(),
            ]))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('teams.table.person'))
                    ->description(fn (TeamPerson $record): string => $record->email)
                    ->searchable(['name', 'email'])
                    ->default(fn (TeamPerson $record): string => $record->email),
                Tables\Columns\TextColumn::make('role')
                    ->label(__('teams.table.role'))
                    ->badge()
                    ->formatStateUsing(fn (TeamPerson $record): string => $this->isOwner($record)
                        ? __('teams.roles.owner.label')
                        : (Jetstream::findRole($record->role)?->name ?? $record->role)),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('teams.table.status'))
                    ->badge()
                    ->color(fn (TeamPerson $record): string => $record->status === 'invited' ? 'warning' : 'success')
                    ->formatStateUsing(fn (TeamPerson $record): string => $record->status === 'invited'
                        ? __('teams.table.status_invited')
                        : __('teams.table.status_member')),
                Tables\Columns\TextColumn::make('happened_at')
                    ->label(__('teams.table.since'))
                    ->date(),
            ])
            ->recordActions([
                $this->updateTeamRoleAction(),
                $this->removeTeamMemberAction(),
                $this->leaveTeamAction(),
                $this->resendTeamInvitationAction(),
                $this->copyInviteLinkAction(),
                $this->revokeTeamInvitationAction(),
            ]);
    }

    private function isOwner(TeamPerson $record): bool
    {
        return $record->user_id !== null && $record->user_id === $this->team->user_id;
    }
}
```

Each action follows this shape — `updateTeamRole` shown in full, the rest below it:

```php
private function updateTeamRoleAction(): Action
{
    return Action::make('updateTeamRole')
        ->label(__('teams.actions.update_team_role'))
        ->visible(fn (TeamPerson $record): bool => $record->status === 'member'
            && ! $this->isOwner($record)
            && Gate::check('updateTeamMember', $this->team))
        ->schema([
            Radio::make('role')
                ->hiddenLabel()
                ->required()
                ->options(fn (): array => $this->assignableRoles())
                ->descriptions(fn (): array => collect(Jetstream::$roles)
                    ->only(array_keys($this->assignableRoles()))
                    ->pluck('description', 'key')
                    ->all())
                ->default(fn (TeamPerson $record): string => $record->role)
                ->rules([
                    fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if ($value === TeamRole::Admin->value && ! Gate::check('promoteToAdmin', $this->team)) {
                            $fail(__('teams.validation.only_owner_promotes_admins'));
                        }
                    },
                ]),
        ])
        ->action(function (TeamPerson $record, array $data): void {
            Gate::authorize('updateTeamMember', $this->team);

            if ($data['role'] === TeamRole::Admin->value) {
                Gate::authorize('promoteToAdmin', $this->team);
            }

            // `user_id` is nullable on TeamPerson (invitation rows carry none),
            // so narrow before passing it to an action that requires a string.
            $userId = $record->user_id;

            if ($userId === null) {
                return;
            }

            resolve(UpdateTeamMemberRole::class)->update(
                $this->authUser(),
                $this->team,
                $userId,
                $data['role'],
            );

            $this->sendNotification(__('teams.notifications.role_updated.success'));

            $this->resetTable();
        });
}

/** @return array<string, string> */
private function assignableRoles(): array
{
    $roles = collect(Jetstream::$roles)->pluck('name', 'key');

    if (! Gate::check('promoteToAdmin', $this->team)) {
        $roles = $roles->except(TeamRole::Admin->value);
    }

    return $roles->all();
}
```

The remaining five actions in full. `removeTeamMember` and `leaveTeam` both hide on the owner row — that is what closes A8.

```php
private function removeTeamMemberAction(): Action
{
    return Action::make('removeTeamMember')
        ->label(__('teams.actions.remove_team_member'))
        ->color('danger')
        ->requiresConfirmation()
        ->visible(fn (TeamPerson $record): bool => $record->status === 'member'
            && ! $this->isOwner($record)
            && $record->user_id !== (string) $this->authUser()->id
            && Gate::check('removeTeamMember', $this->team))
        ->action(function (TeamPerson $record): void {
            $member = User::query()->find($record->user_id);

            if ($member === null) {
                return;
            }

            try {
                resolve(RemoveTeamMember::class)->remove($this->authUser(), $this->team, $member);
                $this->sendNotification(__('teams.notifications.team_member_removed.success'));
            } catch (AuthorizationException) {
                $this->sendNotification(__('teams.notifications.permission_denied.cannot_remove_team_member'), type: 'danger');
            } catch (ValidationException $exception) {
                $this->sendNotification($exception->validator->errors()->first(), type: 'danger');
            }

            $this->resetTable();
        });
}

private function leaveTeamAction(): Action
{
    return Action::make('leaveTeam')
        ->label(__('teams.actions.leave_team'))
        ->icon('heroicon-o-arrow-right-start-on-rectangle')
        ->color('danger')
        ->modalDescription(__('teams.modals.leave_team.notice'))
        ->requiresConfirmation()
        // Hidden on the owner row: RemoveTeamMember always rejects the owner,
        // so showing it could only ever produce an error (defect A8).
        ->visible(fn (TeamPerson $record): bool => $record->status === 'member'
            && ! $this->isOwner($record)
            && $record->user_id === (string) $this->authUser()->id)
        ->action(function (): void {
            $user = $this->authUser();

            try {
                resolve(RemoveTeamMember::class)->remove($user, $this->team, $user);
                $this->sendNotification(__('teams.notifications.leave_team.success'));
                $this->redirect(Filament::getHomeUrl());
            } catch (ValidationException $exception) {
                $this->sendNotification($exception->validator->errors()->first(), type: 'danger');
            }
        });
}

private function resendTeamInvitationAction(): Action
{
    return Action::make('resendTeamInvitation')
        ->label(__('teams.actions.resend_team_invitation'))
        ->color('primary')
        ->requiresConfirmation()
        ->visible(fn (TeamPerson $record): bool => $record->status === 'invited'
            && Gate::check('updateTeamMember', $this->team))
        ->action(function (TeamPerson $record): void {
            $invitation = $this->invitationFor($record);

            if ($invitation === null) {
                return;
            }

            $key = "resend-invitation:{$invitation->getKey()}";

            if (RateLimiter::tooManyAttempts($key, 1)) {
                $this->sendNotification(__('teams.notifications.resend_throttled', [
                    'seconds' => RateLimiter::availableIn($key),
                ]), type: 'warning');

                return;
            }

            RateLimiter::hit($key, 60);

            resolve(ResendTeamInvitation::class)->resend($invitation);

            $this->sendNotification(__('teams.notifications.team_invitation_sent.success'));
            $this->resetTable();
        });
}

private function copyInviteLinkAction(): Action
{
    return Action::make('copyInviteLink')
        ->label(__('teams.actions.copy_invite_link'))
        ->color('gray')
        ->visible(fn (TeamPerson $record): bool => $record->status === 'invited'
            && Gate::check('updateTeamMember', $this->team))
        ->action(function (TeamPerson $record): void {
            $invitation = $this->invitationFor($record);

            // Only a resend can mint a fresh raw token — the stored value is a
            // hash — so legacy rows still hand out their signed URL.
            if ($invitation === null) {
                return;
            }

            $url = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

            $this->js('navigator.clipboard.writeText('.json_encode($url, JSON_THROW_ON_ERROR).')');

            $this->sendNotification(__('teams.notifications.invite_link_copied.success'));
        });
}

private function revokeTeamInvitationAction(): Action
{
    return Action::make('revokeTeamInvitation')
        ->label(__('teams.actions.revoke_team_invitation'))
        ->color('danger')
        ->requiresConfirmation()
        ->visible(fn (TeamPerson $record): bool => $record->status === 'invited'
            && Gate::check('removeTeamMember', $this->team))
        ->action(function (TeamPerson $record): void {
            $invitation = $this->invitationFor($record);

            if ($invitation === null) {
                return;
            }

            resolve(RevokeTeamInvitation::class)->revoke($invitation);

            $this->sendNotification(__('teams.notifications.team_invitation_revoked.success'));
            $this->resetTable();
        });
}

/**
 * Resolve an invitation row back to its model, re-asserting tenant ownership.
 * The record key arrives from the client, so the team scope is the boundary.
 *
 * Aborts rather than returning null on a miss: the replaced
 * PendingTeamInvitations component answered a foreign invitation with
 * `abort_unless($invitation->team_id === $this->team->id, 403)`, and
 * PendingTeamInvitationsCrossTenantTest pins that contract. Returning null
 * here would soften a 403 into a silent no-op — still safe, but a weaker
 * guarantee than the one the suite already holds us to.
 */
private function invitationFor(TeamPerson $record): TeamInvitation
{
    Gate::authorize('updateTeamMember', $this->team);

    $invitation = TeamInvitation::query()
        ->whereKey($record->source_id)
        ->where('team_id', $this->team->id)
        ->first();

    abort_if($invitation === null, 403);

    return $invitation;
}
```

Because the return type is now non-nullable, drop the `if ($invitation === null) { return; }` guard from each of the four callers above.

- [ ] **Step 4: Extract the role update into an action**

The current `TeamMembers::updateTeamRole` writes `updateExistingPivot` inline, which `EloquentWriteOutsideActionRule` forbids in a Livewire component. Create `app/Actions/Jetstream/UpdateTeamMemberRole.php`:

```php
final readonly class UpdateTeamMemberRole
{
    public function update(User $user, Team $team, string $memberId, string $role): void
    {
        Gate::forUser($user)->authorize('updateTeamMember', $team);

        if ($role === TeamRole::Admin->value) {
            Gate::forUser($user)->authorize('promoteToAdmin', $team);
        }

        $team->users()->updateExistingPivot($memberId, ['role' => $role]);

        event(new TeamMemberUpdated($team->fresh(), $team->users()->find($memberId)->membership));
    }
}
```

- [ ] **Step 5: Create the view and point the page at it**

`resources/views/livewire/app/teams/manage-members.blade.php`:

```blade
<div>
    {{ $this->table }}

    <x-filament-actions::modals/>
</div>
```

In `app/Filament/Pages/Team/Members.php`, replace the three-component form with the single component and drop the now-unused `form()` method if the page has no other schema:

```php
public function form(Schema $schema): Schema
{
    /** @var Team $tenant */
    $tenant = Filament::getTenant();

    return $schema->components([
        Livewire::make(ManageMembers::class)->data(['team' => $tenant]),
    ]);
}
```

- [ ] **Step 6: Add every new translation key**

`HardcodedUserFacingStringRule` fails PHPStan on any unwrapped string, so all of these must exist in `lang/en/teams.php` before analysis passes. Merge into the existing blocks rather than creating duplicates.

```php
'table' => [
    'heading' => 'People',
    'counts' => ':members members, :pending pending',
    'person' => 'Person',
    'role' => 'Role',
    'status' => 'Status',
    'since' => 'Since',
    'status_member' => 'Member',
    'status_invited' => 'Invitation pending',
],

// merge into the existing 'actions' block
'invite_people' => 'Invite people',
'add_another' => 'Add another',
'invite_link' => 'Invite link',
'rotate_invite_link' => 'Generate a new link',

// merge into the existing 'roles' block
'owner' => [
    'label' => 'Owner',
],

'invite_link' => [
    'url' => 'Anyone with this link can join',
    'default_role' => 'Role for people who join with this link',
],

'validation' => [
    'only_owner_promotes_admins' => 'Only the workspace owner can grant the Administrator role.',
],

// merge into the existing 'notifications' block
'role_updated' => [
    'success' => 'Role updated.',
],
'invite_link_rotated' => [
    'success' => 'A new invite link was generated. The previous link no longer works.',
],
'resend_throttled' => 'Please wait :seconds seconds before resending.',
'some_invites_failed' => [
    'title' => 'Some invitations could not be sent',
],
```

Note the collision: `actions.invite_link` (a button label) and the top-level `invite_link` block are different keys at different depths. If that reads as confusing in review, rename the button key to `actions.manage_invite_link` and update the action.

- [ ] **Step 7: Delete the replaced components**

```bash
rm app/Livewire/App/Teams/TeamMembers.php \
   app/Livewire/App/Teams/PendingTeamInvitations.php \
   app/Livewire/App/Teams/AddTeamMember.php \
   resources/views/livewire/app/teams/team-members.blade.php \
   resources/views/livewire/app/teams/pending-team-invitations.blade.php \
   resources/views/livewire/app/teams/add-team-member.blade.php
```

- [ ] **Step 8: Migrate the four test files that drove the deleted components**

Confirm the blast radius first:

```bash
grep -rln "Livewire\\\\App\\\\Teams\\\\AddTeamMember\|PendingTeamInvitations\|Livewire\\\\App\\\\Teams\\\\TeamMembers" tests/
```

Expected: exactly four files. `InviteLinkTokenTest.php` will **not** appear — it imports `App\Actions\Jetstream\AddTeamMember`, the action, which stays. If a `grep` for the bare string `AddTeamMember` is used instead it produces that false positive; use the namespaced pattern above.

Three mechanical rules cover almost every edit:

| Old | New | Why |
|---|---|---|
| `livewire(AddTeamMember::class, ['team' => $t])->fillForm([...])->call('addTeamMember', $t)` | `livewire(ManageMembers::class, ['team' => $t])->callAction('invitePeople', ['invites' => [['email' => ..., 'role' => ...]]])` | the invite form is a header-action modal taking a repeater (Task 12) |
| `livewire(PendingTeamInvitations::class, ['team' => $t])` and `livewire(TeamMembers::class, ['team' => $t])` | `livewire(ManageMembers::class, ['team' => $t])` | one component |
| `TestAction::make('x')->table($invitation)` | `TestAction::make('x')->table('invite:'.$invitation->id)` | records are `TeamPerson` rows keyed `invite:<ulid>` / `member:<bigint>`, not `TeamInvitation` models |

Action names are unchanged, so no test needs an action rename.

**`InviteTeamMemberTest.php` (4 tests).** All four drive `AddTeamMember`. Rule 1 applies to each. The disposable-email test asserts `->assertNotified(__('validation.indisposable'))`; the new `sendInvitations()` loop reports per-row failures through the `some_invites_failed` notification, so that assertion becomes:

```php
test('team members cannot be invited with a disposable email address', function (): void {
    livewire(ManageMembers::class, ['team' => $this->team])
        ->callAction('invitePeople', [
            'invites' => [['email' => 'burner@mailinator.com', 'role' => 'admin']],
        ])
        ->assertNotified(__('teams.notifications.some_invites_failed.title'));

    expect($this->team->fresh()->teamInvitations)->toHaveCount(0);
});
```

The behavioural guarantee — a disposable address creates no invitation — is preserved exactly; only the notification channel moved. Do not drop the `toHaveCount(0)` assertion.

**`ManageTeamInvitationsTest.php` (12 tests).** Only 5 touch the component: `team owner can copy invite link`, `pending invitations table shows invitations`, `team owner can revoke a pending invitation`, `old cancel action name is gone`, plus the resend test added in Task 5. The 7 `isExpired()` and cleanup-command tests are model- and command-level and must not be touched. For `pending invitations table shows invitations`, `assertCanSeeTableRecords([$invitation])` passes models and will no longer match — assert on the projection instead:

```php
livewire(ManageMembers::class, ['team' => $user->currentTeam])
    ->assertCanSeeTableRecords([TeamPerson::forTeam($user->currentTeam)->find('invite:'.$invitation->id)]);
```

If that reads awkwardly, `->assertSee('pending@example.com')` is an acceptable substitute — it still proves the invitation surfaces in the list.

**`TeamSettingsInvitationFlowTest.php` (7 tests).** The first two assert *which* Livewire components each settings page composes. They are the tests that pin this refactor, so update the expectations rather than deleting them:

```php
test('the general tab owns workspace name and deletion', function (): void {
    // ...
    expect($components)->toContain(UpdateTeamName::class)
        ->and($components)->not->toContain(ManageMembers::class);
});

test('the members tab owns invitations and membership', function (): void {
    // ...
    expect($components)->toContain(ManageMembers::class);
});
```

Drop the `not->toContain('App\\Livewire\\App\\Teams\\ManageInviteLink')` clause from the second test — that class never existed, and invite-link management now legitimately lives on this page via the `manageInviteLink` header action (Task 12). Rename the test accordingly.

The remaining five follow rules 1–3. `Mail::assertSent(TeamInvitationMail::class)` must now import `App\Mail\TeamInvitationMail` (Task 6), not `Laravel\Jetstream\Mail\TeamInvitation`.

**`PendingTeamInvitationsCrossTenantTest.php` (3 tests).** The most important file in this step — it proves an admin of team A cannot revoke, resend, or copy a link for team B's invitation.

Its current form calls public Livewire methods directly (`->call('revokeTeamInvitation', $victimInvitation)`) and asserts `assertForbidden()`. Those public methods no longer exist; the logic lives in action closures. Rewrite each test to attack through the action with a forged record key, and assert the **outcome** rather than the mechanism — the foreign row is not in `TeamPerson::forTeam($attackerTeam)`, so Filament may reject at record resolution before `invitationFor()` runs, and pinning one specific failure mode would make the test brittle:

```php
use App\Livewire\App\Teams\ManageMembers;

mutates(ManageMembers::class);

it('prevents an admin of team A from revoking an invitation belonging to team B', function (): void {
    $attacker = User::factory()->withPersonalTeam()->create();
    $attackerTeam = $attacker->personalTeam();

    $victimOwner = User::factory()->withPersonalTeam()->create();
    $victimTeam = $victimOwner->personalTeam();

    $victimInvitation = $victimTeam->teamInvitations()->create([
        'email' => 'bystander@example.com',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $this->actingAs($attacker);

    $component = livewire(ManageMembers::class, ['team' => $attackerTeam]);

    $component->assertDontSee('bystander@example.com');

    rescue(fn () => $component->callAction(
        TestAction::make('revokeTeamInvitation')->table('invite:'.$victimInvitation->id)
    ));

    expect($victimTeam->teamInvitations()->whereKey($victimInvitation->id)->exists())->toBeTrue();
});
```

`rescue()` swallows whichever rejection fires — 403 from `invitationFor()` or a resolution failure — while the surviving invitation proves the boundary held. Repeat for `resendTeamInvitation` (assert `Mail::assertNothingSent()` after `Mail::fake()`) and `copyInviteLink` (assert the invitation still exists and no notification claimed success).

Add a fourth test covering the member half of the same boundary, which the old file could not express because members and invitations lived in separate components:

```php
it('prevents an admin of team A from removing a member of team B', function (): void {
    $attacker = User::factory()->withPersonalTeam()->create();
    $victimOwner = User::factory()->withPersonalTeam()->create();
    $victimTeam = $victimOwner->personalTeam();

    $victimMember = User::factory()->create();
    $victimTeam->users()->attach($victimMember, ['role' => TeamRole::Editor->value]);

    $membershipId = $victimTeam->users()->where('users.id', $victimMember->id)->first()->membership->id;

    $this->actingAs($attacker);

    rescue(fn () => livewire(ManageMembers::class, ['team' => $attacker->personalTeam()])
        ->callAction(TestAction::make('removeTeamMember')->table('member:'.$membershipId)));

    expect($victimMember->fresh()->belongsToTeam($victimTeam))->toBeTrue();
});
```

Consider renaming the file to `ManageMembersCrossTenantTest.php` to match the component it now covers.

Note: `tests/.pest/shards.json` also names the deleted test classes, but it is regenerated once at the end (Task 16) rather than here — Tasks 12 and 13 add further tests, and each regeneration costs a full parallel suite run.

- [ ] **Step 9: Run the full Teams suite**

Run: `php artisan test --compact tests/Feature/Teams`
Expected: PASS

- [ ] **Step 10: Run quality gates and commit**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/rector --dry-run && vendor/bin/phpstan analyse && composer test:type-coverage
git add -A app/Livewire app/Actions/Jetstream app/Filament/Pages/Team resources/views/livewire lang/en/teams.php tests/Feature/Teams
git commit -m "refactor: merge team member widgets into one unified list"
```

---

### Task 12: Invite modal with multi-email and invite-link management

**Files:**
- Modify: `app/Livewire/App/Teams/ManageMembers.php`
- Modify: `app/Http/Controllers/JoinTeamViaLinkController.php:63`
- Create: `app/Actions/Jetstream/UpdateInviteLinkSettings.php`
- Modify: `lang/en/teams.php`
- Test: `tests/Feature/Teams/ManageMembersTest.php`, `tests/Feature/Teams/RotateInviteLinkTest.php`, `tests/Feature/Teams/InviteLinkTokenTest.php`

**Interfaces:**
- Consumes: `InviteTeamMember::invite()` (Task 5), `Team::rotateInviteLink()`, `Team::$invite_link_default_role` (Task 4).
- Produces: header actions `invitePeople` and `manageInviteLink` on `ManageMembers`. `UpdateInviteLinkSettings::update(User, Team, string $role): void` and `::rotate(User, Team): void`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Teams/ManageMembersTest.php`:

```php
test('several people can be invited at once', function (): void {
    livewire(ManageMembers::class, ['team' => $this->team])
        ->callAction('invitePeople', [
            'invites' => [
                ['email' => 'one@example.test', 'role' => TeamRole::Editor->value],
                ['email' => 'two@example.test', 'role' => TeamRole::Viewer->value],
            ],
        ]);

    expect($this->team->fresh()->teamInvitations->pluck('email')->all())
        ->toEqualCanonicalizing(['one@example.test', 'two@example.test']);
});

test('an admin cannot invite someone as an admin', function (): void {
    $admin = User::factory()->create();
    $this->team->users()->attach($admin, ['role' => TeamRole::Admin->value]);
    $this->actingAs($admin);

    livewire(ManageMembers::class, ['team' => $this->team])
        ->callAction('invitePeople', [
            'invites' => [['email' => 'nope@example.test', 'role' => TeamRole::Admin->value]],
        ])
        ->assertHasActionErrors();

    expect($this->team->fresh()->teamInvitations)->toHaveCount(0);
});
```

Append to `tests/Feature/Teams/InviteLinkTokenTest.php`:

```php
test('the join link grants the configured default role', function (): void {
    $team = User::factory()->withTeam()->create()->currentTeam;
    $team->update(['invite_link_default_role' => App\Enums\TeamRole::Viewer->value]);

    $joiner = User::factory()->create();

    $this->actingAs($joiner)
        ->post(route('teams.join.confirm', ['token' => $team->invite_link_token]))
        ->assertRedirect();

    expect($joiner->fresh()->teamRole($team->fresh())->key)
        ->toBe(App\Enums\TeamRole::Viewer->value);
});

test('teams without a configured default still grant editor', function (): void {
    $team = User::factory()->withTeam()->create()->currentTeam;
    $joiner = User::factory()->create();

    $this->actingAs($joiner)
        ->post(route('teams.join.confirm', ['token' => $team->invite_link_token]))
        ->assertRedirect();

    expect($joiner->fresh()->teamRole($team->fresh())->key)
        ->toBe(App\Enums\TeamRole::Editor->value);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="invited at once|configured default role"`
Expected: FAIL — the header action and the column wiring do not exist.

- [ ] **Step 3: Add the invite header action**

In `ManageMembers::table()`, add `->headerActions([$this->invitePeopleAction(), $this->manageInviteLinkAction()])`:

```php
private function invitePeopleAction(): Action
{
    return Action::make('invitePeople')
        ->label(__('teams.actions.invite_people'))
        ->visible(fn (): bool => Gate::check('addTeamMember', $this->team))
        ->modalWidth('lg')
        ->schema([
            Repeater::make('invites')
                ->hiddenLabel()
                ->defaultItems(1)
                ->addActionLabel(__('teams.actions.add_another'))
                ->schema([
                    TextInput::make('email')
                        ->label(__('teams.form.email.label'))
                        ->email()
                        ->required(),
                    Select::make('role')
                        ->label(__('teams.table.role'))
                        ->options(fn (): array => $this->assignableRoles())
                        ->default(TeamRole::Editor->value)
                        ->required(),
                ])
                ->columns(2),
        ])
        ->action(function (array $data): void {
            $this->sendInvitations($data['invites']);
        });
}
```

`assignableRoles()` (Task 11) already excludes Admin for non-owners, so an Admin cannot invite an Admin.

- [ ] **Step 4: Implement the send loop**

Mirror `CreateTeam::sendOnboardingInvites` — per-row failures are reported, not swallowed, and one bad address does not discard the rest:

```php
/** @param array<int, array{email: ?string, role: ?string}> $invites */
private function sendInvitations(array $invites): void
{
    try {
        $this->rateLimit(5);
    } catch (TooManyRequestsException $exception) {
        $this->sendRateLimitedNotification($exception);

        return;
    }

    $failures = [];
    $sent = 0;

    foreach ($invites as $invite) {
        if (blank($invite['email'] ?? null)) {
            continue;
        }

        try {
            resolve(InviteTeamMember::class)->invite(
                $this->authUser(),
                $this->team,
                (string) $invite['email'],
                $invite['role'] ?? TeamRole::Editor->value,
            );

            $sent++;
        } catch (ValidationException $exception) {
            $failures[] = "{$invite['email']}: {$exception->validator->errors()->first()}";
        }
    }

    if ($sent > 0) {
        $this->sendNotification(__('teams.notifications.team_invitation_sent.success'));
    }

    if ($failures !== []) {
        $this->sendNotification(
            __('teams.notifications.some_invites_failed.title'),
            implode("\n", $failures),
            'warning',
        );
    }

    $this->resetTable();
}
```

`resetTable()` refreshes the unified list in place — this replaces the full-page `redirect()` the old `AddTeamMember` performed.

- [ ] **Step 5: Add the invite-link action**

```php
private function manageInviteLinkAction(): Action
{
    return Action::make('manageInviteLink')
        ->label(__('teams.actions.invite_link'))
        ->color('gray')
        ->visible(fn (): bool => Gate::check('addTeamMember', $this->team))
        ->schema([
            TextEntry::make('url')
                ->label(__('teams.invite_link.url'))
                ->state(fn (): string => route('teams.join', ['token' => $this->team->invite_link_token]))
                ->copyable(),
            Select::make('invite_link_default_role')
                ->label(__('teams.invite_link.default_role'))
                ->options(fn (): array => $this->assignableRoles())
                ->default(fn (): string => $this->team->invite_link_default_role)
                ->required(),
        ])
        ->extraModalFooterActions([
            Action::make('rotateInviteLink')
                ->label(__('teams.actions.rotate_invite_link'))
                ->color('danger')
                ->requiresConfirmation()
                ->action(function (): void {
                    resolve(UpdateInviteLinkSettings::class)->rotate($this->authUser(), $this->team);
                    $this->sendNotification(__('teams.notifications.invite_link_rotated.success'));
                }),
        ])
        ->action(function (array $data): void {
            resolve(UpdateInviteLinkSettings::class)->update(
                $this->authUser(),
                $this->team,
                $data['invite_link_default_role'],
            );

            $this->sendNotification();
        });
}
```

- [ ] **Step 6: Create the action class**

`app/Actions/Jetstream/UpdateInviteLinkSettings.php`:

```php
final readonly class UpdateInviteLinkSettings
{
    public function update(User $user, Team $team, string $role): void
    {
        Gate::forUser($user)->authorize('addTeamMember', $team);

        if ($role === TeamRole::Admin->value) {
            Gate::forUser($user)->authorize('promoteToAdmin', $team);
        }

        $team->update(['invite_link_default_role' => $role]);
    }

    public function rotate(User $user, Team $team): void
    {
        Gate::forUser($user)->authorize('addTeamMember', $team);

        $team->rotateInviteLink();
    }
}
```

- [ ] **Step 7: Honour the configured role when joining**

In `app/Http/Controllers/JoinTeamViaLinkController.php`, replace the hardcoded `TeamRole::Editor->value`:

```php
$adder->add(
    $owner,
    $team,
    $user->email,
    $team->invite_link_default_role,
);
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test --compact --filter="invited at once|configured default role|InviteLinkTokenTest|RotateInviteLinkTest"`
Expected: PASS

- [ ] **Step 9: Run quality gates and commit**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/rector --dry-run && vendor/bin/phpstan analyse && composer test:type-coverage
git add app/Livewire app/Actions/Jetstream app/Http/Controllers/JoinTeamViaLinkController.php lang/en/teams.php tests/Feature/Teams
git commit -m "feat: multi-email invite modal with invite-link management"
```

---

### Task 13: Fix the account-deletion dead end

**Files:**
- Modify: `app/Actions/Jetstream/ScheduleUserDeletion.php:38-42`
- Test: `tests/Feature/Teams/ScheduleTeamDeletionTest.php`

**Interfaces:**
- No new interfaces.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Teams/ScheduleTeamDeletionTest.php`:

```php
test('the deletion blocker names an action the app supports', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;
    $team->users()->attach(User::factory()->create(), ['role' => App\Enums\TeamRole::Editor->value]);

    try {
        resolve(App\Actions\Jetstream\ScheduleUserDeletion::class)->schedule($owner);
        $this->fail('Expected a validation exception.');
    } catch (Illuminate\Validation\ValidationException $exception) {
        $message = $exception->validator->errors()->first('team');

        expect($message)->not->toContain('Transfer ownership')
            ->and($message)->toContain($team->name);
    }
});
```

Check `ScheduleUserDeletion`'s public method name before running and adjust the call if it is not `schedule`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="names an action the app supports"`
Expected: FAIL — the message still says "Transfer ownership".

- [ ] **Step 3: Rewrite the message**

```php
throw ValidationException::withMessages([
    'team' => [__('teams.validation.remove_members_before_deleting', [
        'teams' => $teamsWithMembers->implode(', '),
    ])],
]);
```

With the translation:

```php
'remove_members_before_deleting' => 'Remove all members from these workspaces, or delete the workspaces, before deleting your account: :teams',
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter="names an action the app supports"`
Expected: PASS

- [ ] **Step 5: Run quality gates and commit**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/rector --dry-run && vendor/bin/phpstan analyse && composer test:type-coverage
git add app/Actions/Jetstream/ScheduleUserDeletion.php lang/en/teams.php tests/Feature/Teams/ScheduleTeamDeletionTest.php
git commit -m "fix: stop pointing account deletion at a feature that does not exist"
```

---

### Task 14: Browser verification

**Files:**
- No source changes expected. Any defect found here is fixed in the task that owns the code.

**Interfaces:**
- Consumes: everything from Tasks 1–13.

- [ ] **Step 1: Derive the panel URL and log in**

Never hardcode the host — routing mode is per-checkout.

```bash
php artisan tinker --execute 'echo json_encode(["base"=>config("app.url"),"app_domain"=>config("app.app_panel_domain"),"app_path"=>config("app.app_panel_path","app")]);'
export AGENT_BROWSER_SESSION="members-verify-$$"
agent-browser set viewport 1920 1080
agent-browser open "<panel>/login"
agent-browser eval '(() => {
  const e=document.getElementById("form.email"), p=document.getElementById("form.password");
  e.value="manuk.minasyan1@gmail.com"; e.dispatchEvent(new Event("input",{bubbles:true}));
  p.value="password"; p.dispatchEvent(new Event("input",{bubbles:true}));
  [...document.querySelectorAll("form")].find(x=>x.getAttribute("wire:submit")==="authenticate").requestSubmit();
  return "submitted";
})()'
```

- [ ] **Step 2: Walk and shoot the Members page**

Light, dark, and a 390×844 mobile viewport. Screenshot paths must be absolute.

```bash
agent-browser open "<panel>/<team-slug>/team/members"
agent-browser screenshot --full "$(pwd)/.context/members-redesign/after-light.png"
```

Confirm against `.context/members-redesign/current-light.png`: one list, owner badged with no Leave action, pending rows badged, search present, invite form gone from the page body.

- [ ] **Step 3: Walk the invite modal**

Open `invitePeople`, add two rows, send, and confirm the list updates **without a full page reload** and both invitations appear as pending rows.

- [ ] **Step 4: Walk all four accept states**

Use a real invitation each time — do not fake state through tinker.

1. `ready` — sign in as the invitee, open the emailed link, confirm no membership exists until the button is clicked
2. `wrong-account` — open the same link while signed in as someone else
3. `expired` — set `expires_at` into the past on a throwaway invitation, then open its link
4. `already-member` — accept once, then re-open the link

- [ ] **Step 5: Verify the Admin and Viewer experience**

Sign in as an Admin: the Members tab is reachable, invite works, the Admin role option is absent from both the invite and change-role selects. Sign in as a Viewer: records render read-only, with no create button on Companies.

- [ ] **Step 6: Read every screenshot back**

A failed Livewire request leaves a full-screen error overlay that silently photobombs later shots. Read each PNG with the Read tool and confirm it shows what you expect before treating it as evidence.

---

### Task 15: Help documentation

**Files:**
- Modify: `packages/Documentation/resources/content/help/getting-started/invite-your-team.md`
- Modify: `packages/Documentation/resources/content/help/workspace/manage-members-and-roles.md`
- Replace: `public/help-assets/getting-started/invite-your-team-1.png`, `public/help-assets/workspace/manage-members-and-roles-1.png`

**Interfaces:**
- Consumes: the shipped UI from Tasks 11–12.

- [ ] **Step 1: Rewrite invite-your-team.md**

Steps 1–4 currently describe "Under **Add Member**, type their address into **Email**" and a two-role choice. Replace with the invite modal flow: click **Invite people**, add one or more addresses, pick a role per row, send. Update the front-matter `description` (it names only Administrator and Editor) and bump `updated:` to the merge date.

- [ ] **Step 2: Rewrite manage-members-and-roles.md**

- "## The two roles" becomes three; the table gains a Viewer column and corrects the Administrator row — Admins now manage members
- The `description` front-matter says "What Administrators and Editors can each do" — add Viewers
- The join-link paragraph moves from "offered on the invite step when you create a workspace" to the invite modal, and documents the configurable default role
- The last sentence — "Members can also **Leave** a workspace themselves, except the one they created" — becomes accurate: owners have no Leave action at all
- Bump `updated:`

- [ ] **Step 3: Re-shoot both screenshots**

Both existing images show the old three-section layout. Invoke `Skill('screenshot-with-callout')` per shot and match the original dimensions and framing.

- [ ] **Step 4: Verify the docs render**

```bash
agent-browser open "<base>/help/getting-started/invite-your-team"
agent-browser open "<base>/help/workspace/manage-members-and-roles"
```

Confirm both pages render and both images resolve.

- [ ] **Step 5: Commit**

```bash
git add packages/Documentation public/help-assets
git commit -m "docs: update member and invitation help for the new flow"
```

---

### Task 16: Final gate

- [ ] **Step 1: Run every quality gate**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
```

All four clean. If rector reports a phar path from a deleted workspace, clear the stale global cache with `rm -rf "$TMPDIR/cache"` — it masks real findings.

- [ ] **Step 2: Run the full suite**

```bash
composer test:pest
php -d memory_limit=2G vendor/bin/pest tests/Arch
```

The Arch suite OOMs at the default 128M locally; the explicit limit is expected, not a workaround for a new failure.

- [ ] **Step 3: Refresh the CI shard balance**

`tests/.pest/shards.json` still names the component test classes deleted in Task 11, and does not name the files added in Tasks 2, 9, 10, and 11. A stale file silently drops classes out of CI time-balancing, so regenerate it now that the suite has reached its final shape.

```bash
composer test:update-shards
git add tests/.pest/shards.json
git commit -m "chore: refresh test shard balance"
```

- [ ] **Step 4: Re-run the SystemAdmin enum sweep**

```bash
grep -rn "TeamRole\|'admin'\|'editor'\|pivot.role\|roleName" packages/SystemAdmin/src
```

Expected: no output.

- [ ] **Step 5: Confirm no stale references to deleted classes**

```bash
grep -rn "AddTeamMember\|PendingTeamInvitations\|invitation-expired" app packages resources routes tests
```

Only `App\Actions\Jetstream\AddTeamMember` (the Jetstream `AddsTeamMembers` action, which stays) may appear.

- [ ] **Step 6: Review the diff**

```bash
git diff origin/main --stat
git diff origin/main
```

- [ ] **Step 7: Open the PR**

Body must state that existing non-owner Admins gain member-management ability on deploy — a user-visible privilege change — and that the legacy `/team-invitations/{invitation}` route can be deleted once all pre-deploy invitations have expired (7 days).

```bash
gh pr create --base main --title "feat: overhaul team invitations and membership"
```
