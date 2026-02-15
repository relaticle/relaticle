# Team Resource Relation Managers & Dashboard Links

## Summary

Add read-only relation managers to `TeamResource` in the sysadmin panel so administrators can view all related records (members, companies, people, tasks, opportunities, notes) when inspecting a team. Update the dashboard's `TopTeamsTableWidget` to link team names to the team view page.

## Changes

### 1. ViewTeam Page

Create `ViewTeam` page extending `ViewRecord` with an infolist (team name, slug, owner, personal flag, created_at) and 6 relation managers attached.

Path: `app-modules/SystemAdmin/src/Filament/Resources/TeamResource/Pages/ViewTeam.php`

### 2. Relation Managers (read-only)

All at `app-modules/SystemAdmin/src/Filament/Resources/TeamResource/RelationManagers/`

| File | Relationship | Type | Key columns |
|---|---|---|---|
| `MembersRelationManager.php` | `users` (BelongsToMany) | Team members | name, email, role, joined |
| `CompaniesRelationManager.php` | `companies` (HasMany) | CRM companies | name, domain, created_at |
| `PeopleRelationManager.php` | `people` (HasMany) | CRM contacts | name, email, company, created_at |
| `TasksRelationManager.php` | `tasks` (HasMany) | Tasks | title, status, assignee, due_date |
| `OpportunitiesRelationManager.php` | `opportunities` (HasMany) | Deals | title, status, value, created_at |
| `NotesRelationManager.php` | `notes` (HasMany) | Notes | title, notable_type, created_at |

No create/edit/delete actions — sysadmin is read-only for tenant data.

### 3. TeamResource Updates

- Register `ViewTeam` page in `getPages()`
- Add `ViewAction` to table row actions (already present)

### 4. Dashboard Link

Make team name column in `TopTeamsTableWidget` clickable via `->url()` pointing to TeamResource view page.

## Out of Scope

- UserResource relation managers
- Write actions on relation manager rows
- New dashboard widgets
