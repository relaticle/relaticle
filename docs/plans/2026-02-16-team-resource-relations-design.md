# Team & User Resource Relation Managers & Dashboard Links

## Summary

Add read-only relation managers to `TeamResource` and `UserResource` in the sysadmin panel so administrators can view all related records when inspecting a team or user. Update the dashboard's `TopTeamsTableWidget` to link team and owner names to their view pages.

## Changes

### 1. ViewTeam Page

Create `ViewTeam` page extending `ViewRecord` with an infolist (team name, slug, owner, personal flag, created_at) and 6 relation managers attached.

Path: `app-modules/SystemAdmin/src/Filament/Resources/TeamResource/Pages/ViewTeam.php`

### 2. Team Relation Managers (read-only)

All at `app-modules/SystemAdmin/src/Filament/Resources/TeamResource/RelationManagers/`

| File | Relationship | Type | Notes |
|---|---|---|---|
| `MembersRelationManager.php` | `users` (BelongsToMany) | Team members | Read-only table with name, email, membership.created_at |
| `CompaniesRelationManager.php` | `companies` (HasMany) | CRM companies | Read-only table with name, creation_source, created_at |
| `PeopleRelationManager.php` | `people` (HasMany) | CRM contacts | Read-only table with name, email, creation_source, created_at |
| `TasksRelationManager.php` | `tasks` (HasMany) | Tasks | Read-only table with title, creation_source, created_at |
| `OpportunitiesRelationManager.php` | `opportunities` (HasMany) | Deals | Read-only table with name, creation_source, created_at |
| `NotesRelationManager.php` | `notes` (HasMany) | Notes | Read-only table with title, creation_source, created_at |

No create/edit/delete actions — sysadmin is read-only for tenant data.

### 3. User Relation Managers (read-only)

All at `app-modules/SystemAdmin/src/Filament/Resources/UserResource/RelationManagers/`

| File | Relationship | Type | Notes |
|---|---|---|---|
| `OwnedTeamsRelationManager.php` | `ownedTeams` (HasMany) | Teams created by user | name (linked), personal_team flag, created_at |
| `TeamsRelationManager.php` | `teams` (BelongsToMany) | Teams user is member of | name (linked), membership.role badge, membership.created_at |

### 4. Resource Updates

- Register `ViewTeam` page in TeamResource `getPages()`
- Add infolist to TeamResource and UserResource
- Add relation manager count badges via `getBadge()`
- Implement `HasColor` on `CreationSource` enum to centralize badge colors

### 5. Dashboard Links

- Team name column links to TeamResource view page
- Owner name column links to UserResource view page

## Out of Scope

- Write actions on relation manager rows
- New dashboard widgets
