# Team invitations and membership — design

Date: 2026-08-18
Branch: `ManukMinasyan/team-invitation-workflow`
Ships as: one PR (UI/UX and backend together, by explicit decision)

## Why

Relaticle's invitation flow works but has dead ends, and its role system is
decorative. Eight defects were confirmed by reading the code and driving the
running app; four product decisions were taken to close them.

Reference implementation reviewed: `~/Herd/maxforms` (workspace invitations).
Behaviours are borrowed — explicit confirm, wrong-account recovery, opaque
hashed tokens, accept-time states. Its Volt/Flux implementation shapes are not;
Relaticle stays Filament + Livewire.

## Confirmed defects

| # | Defect | Evidence |
|---|---|---|
| A1 | Accept joins on GET with no confirmation. The sibling `JoinTeamViaLinkController` already does GET-show / POST-join correctly. | `AcceptTeamInvitationController.php:47` |
| A2 | Wrong signed-in account produces `abort(403)` with no recovery path. | `AcceptTeamInvitationController.php:39` |
| A3 | Account deletion is blocked with *"Transfer ownership of these workspaces before deleting your account"* — no ownership-transfer feature exists anywhere in the codebase. | `ScheduleUserDeletion.php:40` |
| A4 | Email match is case-sensitive: invite `Bob@x.com`, register `bob@x.com`, permanent 403. | `AcceptTeamInvitationController.php:33` |
| A5 | The workspace join link has no settings UI. `rotateInviteLink()`, TTL and expiry all exist on `Team`, but only the create-team wizard uses them. | `Team.php:181`, `CreateTeam.php:270` |
| A6 | Invitation email carries no inviter and no role. `team_invitations` has no `inviter_id`, so it cannot. | migration `2024_08_23_110720` |
| A7 | Members list shows an avatar and an email. No name, no role column, no joined date, no owner badge, `paginated(false)`. | `TeamMembers.php:45` |
| A8 | The owner sees a **Leave** button that can only fail — the action throws *"You may not leave a workspace that you created."* | `TeamMembers.php:99`, `RemoveTeamMember.php:50` |

Also observed in the running app: the invite form consumes ~340px for two
fields; **Add Member** is labelled as if it adds a person directly when it
sends an invitation; the page title *Members* is repeated by a section heading
*Members* below it.

## Decisions taken

| | Decision | Chosen |
|---|---|---|
| B1 | Role depth | Fix Admin so it can manage members; add `Viewer` |
| B2 | Seat limits | None. Build so a limit can be added later without rework |
| B3 | Ownership transfer | Not now — fix the dead-end message; transfer gets its own spec |
| B4 | Auto-join on email verification | No. Consent stays explicit |
| B5 | Join-link role | Configurable per team, defaulting to Editor |
| — | Viewer scope | Fully read-only across all record types |
| — | Existing roles | Left as-is on migration |

**Consequence of leaving roles as-is:** every current non-owner Admin gains
member-management power the moment this deploys. That is the accepted
trade-off (no silent demotions), and it is a user-visible privilege change —
it belongs in the release notes.

## 1. Page architecture

Filament binds one table per Livewire component, so the three stacked
components (`AddTeamMember`, `PendingTeamInvitations`, `TeamMembers`) collapse
into one component with one table.

Members and pending invitations become rows in the **same list**, which is what
makes the page read as a team roster rather than three widgets. This needs a
common row shape, so the table queries a `fromSub()` union:

- `team_user` joined to `users` → id, name, email, role, `status = 'member'`, `joined_at`
- `team_invitations` → id, null name, email, role, `status = 'invited'`, `created_at`

backed by a read-only `App\Models\TeamPerson` model (no writes, no casts, no
relations beyond the team). This is the only new abstraction in the design; it
is justified by the single-list requirement, and it incidentally sidesteps the
`Membership` pivot-cast ULID bug (`filament-pivot-cast-corrupts-ulid-keys`)
because no pivot casts are involved.

**Key types do not match.** `team_user.id` is `bigint`; `team_invitations.id`
is a ULID (`character`) — verified against the running schema. A naive union of
the two id columns fails in Postgres on type mismatch, and row actions need to
know which table a row came from regardless. The model's key is therefore a
prefixed synthetic string, `'member:' || id` and `'invite:' || id`, which
solves both at once.

Writes never go through `TeamPerson`. They go through the existing actions in
`app/Actions/Jetstream/`, per the actions rule in `.ai/architecture`.

### Layout

```
Members                                          [ Invite people ]
Da Nang Co · 3 members, 2 pending
──────────────────────────────────────────────────────────────────
[search]
──────────────────────────────────────────────────────────────────
 MM  Manuk Minasyan             Owner       Joined Mar 3      —
     manuk.minasyan1@gmail.com
 AR  Ana Reyes                  Admin  ▾    Joined Aug 1      ⋯
     ana@example.com
 ○   tab-repro-2@example.test    Editor ▾    Invited 6d ago    ⋯
     Invitation pending
```

- Row actions are contextual: members get *Change role* / *Remove* / *Leave*;
  invitations get *Copy link* / *Resend* / *Revoke*.
- The owner row shows an `Owner` badge, no role control, and no Leave (A8).
- Search over name and email; pagination restored (A7).
- Counts in the subheading.

### Invite modal

The inline form leaves the page. **[Invite people]** opens a modal with two
tabs:

- **By email** — a repeater of address + role rows, mirroring the onboarding
  wizard's shape so both paths look alike. Partial failure reports per-row,
  matching `sendOnboardingInvites`.
- **Invite link** — copy, rotate, disable, and the default-role picker (A5,
  B5). First settings UI for methods that already exist on `Team`.

Sending an invite dispatches a Livewire refresh instead of the current
full-page `redirect()` (`AddTeamMember.php:112`).

## 2. Roles and authorization

`App\Enums\TeamRole` gains `Viewer`. `Owner` is not a role value — ownership
stays `teams.user_id`, as today.

`TeamPolicy` currently answers `ownsTeam` for `addTeamMember`,
`updateTeamMember`, `removeTeamMember` and `update`, which is why an
"Administrator" can neither manage members nor open the Members page
(`Members::canAccess()` calls `update`).

| | invite / revoke | change roles | promote to Admin | rename · delete · billing · custom fields |
|---|---|---|---|---|
| Owner | yes | yes | yes | yes |
| Admin | yes | yes, except other Admins | no | no |
| Editor | no | no | no | no |
| Viewer | no | no | no | no |

`Members::canAccess()` moves onto a new `manageMembers` ability.

### Viewer sweep

Viewer is fully read-only. Today `CompanyPolicy`, `PeoplePolicy`, `TaskPolicy`,
`NotePolicy` and `OpportunityPolicy` consult a role only for `forceDelete` /
`forceDeleteAny` — every other ability resolves on team membership alone, so a
Viewer would otherwise be able to create, update and delete records.

All five policies must return false for Viewer on `create`, `update`, `delete`,
`restore` and their `*Any` variants. This is the largest part of the backend
diff and the part most worth close review.

Out of scope, and deliberately so: the API, MCP tools and chat tools resolve
authorization through the same policies, so Viewer is enforced there without
per-surface changes. This must be verified, not assumed — see testing.

### Enum-case hazard

`packages/SystemAdmin` is excluded from PHPStan, and adding an enum case there
has already caused a production `UnhandledMatchError` — the architecture rules
require a manual `match` sweep whenever an enum gains a case. Swept for this
change on 2026-08-18: `packages/SystemAdmin/src` contains **no** reference to
`TeamRole`, `'admin'`, `'editor'`, `pivot.role` or `roleName`, so `Viewer`
introduces no unhandled match there. Re-run the sweep before merge in case the
package changes underneath this branch.

## 3. Invitation model and token

Accept URLs are `URL::signedRoute()` over the record ULID. Signatures are
bound to the absolute URL, so a cross-host redirect 403s
(`relaticle-signed-urls-are-host-bound`), and the URL is not safely
re-shareable.

Schema changes:

- `team_invitations.inviter_id` — nullable FK to `users`, null on delete (A6)
- `team_invitations.token` — nullable, unique, SHA-256 of a 40-character secret
- `teams.invite_link_default_role` — string, defaults to `editor`, so existing
  teams keep today's hardcoded behaviour (B5, replacing the literal
  `TeamRole::Editor` at `JoinTeamViaLinkController.php:63`)

Token minting lives in one place on the model (`issueToken()`), shared by the
invite and resend paths, mirroring how maxforms avoids drift between them.

### Routes

- `GET /invitations/{token}` → confirm page
- `POST /invitations/{token}` → join

Both under a `no-referrer` response header so the token stays out of the
`Referer` chain.

`GET /team-invitations/{invitation}` **stays**, still `signed`, but renders the
confirm page instead of joining (A1). Invitations already sitting in inboxes
keep working; the route can be deleted once the longest live invitation has
expired (7 days).

Legacy rows have `token = null`, so their confirm page cannot post to
`/invitations/{token}`. A matching `POST /team-invitations/{invitation}` is
registered under the same `signed` middleware and the legacy form posts back to
its own signed URL — the signature validates independently of HTTP method. Old
rows also migrate themselves organically: a resend mints a token through the
shared `issueToken()`, so any invitation that gets resent moves onto the new
path.

Accept runs inside a transaction with `lockForUpdate`, matching the race fix
in #485. Email comparison is `Str::lower()` on both sides (A4).

## 4. Accept flow states

Replaces today's binary of *silently joined* or *403*.

| State | Screen |
|---|---|
| ready | "Join Da Nang Co — Ana Reyes invited you as an Editor" · **Join** / Not now |
| wrong account | "This invitation is for ana@example.com. You're signed in as bob@example.com." · Sign out and switch / Go to my workspace (A2) |
| expired | "This invitation has expired." · Ask Ana to resend / Go to my workspace |
| guest | token stored in `url.intended`, redirect to login; the login and register pages name the inviting team |
| already a member | switch to that team, banner |

`DetectsTeamInvitation` needs updating, not merely reusing. It matches the URL
segment after `team-invitations` and resolves it with `whereKey()` — the new
route's needle is `invitations` and its segment is a raw secret, so it must
resolve via `where('token', hash('sha256', $segment))`. Without this change,
guests arriving on a new-style link get no invitation banner on login or
register. Both needles stay supported for the legacy route's lifetime.

### Pending invitations after independent signup

Someone invited by email may register on their own before ever clicking the
link, in which case no `url.intended` exists and the invitation is invisible to
them. After email verification, any non-expired invitation matching their
address is surfaced as a card — *"You've been invited to Da Nang Co · Join /
Decline"*. This is the consent-preserving replacement for auto-join (B4): it
removes the same dead end without joining anyone to a workspace they never
agreed to. Decline revokes the invitation.

Wrong-account is a plain interstitial page, not maxforms' session-flag +
dashboard-modal handoff — that shape is entangled with their routing and buys
nothing here.

## 5. Invitation email

Adds inviter name, role, and team avatar. Subject becomes
*"Ana Reyes invited you to Da Nang Co on Relaticle"*. Falls back to the team
name when `inviter_id` is null (pre-migration rows).

## 6. Dead-end message

`ScheduleUserDeletion.php:40` currently reads *"Transfer ownership of these
workspaces before deleting your account: {names}"*, naming an operation the app
cannot perform. It becomes *"Remove all members from these workspaces, or
delete them, before deleting your account: {names}"* — both of which are
actions the user can actually take today (B3, A3).

## 7. Help documentation

Both docs describe the current UI step by step and both carry screenshots that
this change invalidates:

- `help/getting-started/invite-your-team.md` — rewrite steps 1–4 for the
  invite modal; re-shoot `invite-your-team-1.png`
- `help/workspace/manage-members-and-roles.md` — role table gains Viewer and
  the corrected Admin capabilities; the "two roles" heading becomes three; the
  join-link paragraph moves from "offered on the invite step when you create a
  workspace" to the settings modal and gains the configurable default role;
  the Leave sentence drops its owner caveat now that the button is hidden;
  re-shoot `manage-members-and-roles-1.png`

Both files carry an `updated:` front-matter date that must be bumped.

## 8. Testing

Extends `tests/Feature/Teams/`, which already covers invite, revoke, rotate,
expiry and cross-tenant isolation. Per the testing rules, new cases go into the
existing file covering the same scope rather than into new files.

New coverage:

- accept requires POST; a GET never mutates membership (A1)
- legacy signed link resolves to the confirm page, and its POST still joins
- a resend mints a token on a legacy row, moving it to the new path
- `DetectsTeamInvitation` renders the banner for a token-style link on both
  login and register
- the post-verification invitation card appears for an independently
  registered invitee; Decline revokes the invitation
- join link grants the team's configured default role, and `editor` for teams
  that never set one (B5)
- wrong-account renders the interstitial, not a 403 (A2)
- `Bob@x.com` invitation accepted by `bob@x.com` (A4)
- Admin can invite, revoke and change roles; Admin cannot promote to Admin
- Viewer is denied create/update/delete on all five record types
- Viewer is denied through the API and an MCP tool — proves the policy path
  covers non-panel surfaces
- owner row exposes no Leave action (A8)
- unified table lists members and invitations with correct status
- invite link rotate/disable from settings (A5)
- concurrent accepts attach exactly one membership

Browser verification (`agent-browser`, light + dark + mobile) of the Members
page, the invite modal, and each of the five accept states, per the UI rules.

## Out of scope

- Ownership transfer (own spec)
- Seat limits (waits on the billing tiers decision, epic #440)
- Auto-join on verification
- Capability-enum RBAC (`WorkspaceCapability` equivalent) — the three-role
  policy model covers current needs; revisit if per-permission grants are asked for
