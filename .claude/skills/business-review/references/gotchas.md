# Gotchas — named failure modes from real runs (every hard claim is DATED)

Pattern per entry: symptom → root cause → detect → avoid. **Stale-lore rule:** each
environmental claim carries `verified:`; when the date is >1 month old and the claim
blocks you, RE-TEST it instead of obeying (a hard claim in a sibling skill stayed false
for weeks and kept forcing workarounds). When a claim proves wrong, fix it here in the
same run (`environment.md` self-heal).

## Environment

- **Shared local Redis lets ANOTHER Herd app's Horizon eat this app's queue jobs.**
  (verified: 2026-06-11 — Journey's Horizon consumed Relaticle chat jobs; ~90 min lost,
  invalidated a case; Herd auto-respawned the foreign master when killed.)
  Detect: jobs vanish without effects; foreign Horizon processes in `ps`.
  Avoid: dedicated `REDIS_DB` per app in `.env` + the queue-ownership sentinel probe
  (`environment.md` §4) before any queued journey.
- **agent-browser's Chromium may not resolve Herd's Reverb vhost / wrong websocket
  host.** (verified: 2026-06-10, PR 322 — looked like a degraded channel, was a stale JS
  bundle + wrong-host websocket; cleared by `npm run build` + host fix.)
  Detect: repeated websocket errors in `agent-browser console`.
  Avoid: preflight checks Echo connectivity; rebuild before declaring `blocked`.
- **Sync queue silently invalidates queued-flow verification** (`release()`, retries,
  chain caps untestable). (verified: 2026-06-09, PR 321 — user mandated async re-run.)
  Avoid: Tier 3 requires async Horizon (env gate); below Tier 3 record it in frontier.
- **AI credit balance drains mid-review.** (verified: 2026-06)
  Avoid: re-seed `LocalSeeder` (tops credits) before chat journeys; check balance first.
- **CSRF 419 after long idle / stale session after branch switches.** (verified: 2026-05)
  Avoid: `agent-browser reload` before the case; first case of a batch = fresh login.
- **Panel URLs are conditionally domain-routed** — `app.relaticle.test` /
  `sysadmin.relaticle.test` when `*_DOMAIN` envs are set, else `/app`, `/sysadmin`
  paths. (verified: 2026-06-12, `AppPanelProvider.php:88-93`.) NEVER assume either form;
  derive per `environment.md` §1.

## Review process

- **Subagent capacity deaths mid-run.** (verified: 2026-06-11/12 — code-context-analyzer
  died in two consecutive runs; persona fleets hit session limits in two projects.)
  Avoid: capacity-check spawn pattern (`personas.md`), inline fallback recorded in the
  report, self-review boxing (SKILL.md gate 6) when independence is lost.
- **Reviewing the wrong artifact**: PR head missing the fix being discussed; review
  requested before the implementation exists. (verified: 2026-06-05/10, Journey.)
  Avoid: head-SHA record + re-check before publish; claims-vs-diff guard
  (`understand.md` §1).
- **Self-contamination**: a leftover event listener / auto-approve hook from earlier
  probing pollutes a later case; a filter against the wrong Livewire component fakes a
  500. (verified: 2026-06-11/12 — both happened.) Avoid: fresh session per case where
  feasible; before filing a 500 as a bug, reproduce it in a clean session (adversarial
  verification does this by design).
- **Workspace pollution into the PR** (`.claude/settings.local.json` staged; skill edits
  committed to the PR branch moved the head SHA and made the verdict stale).
  (verified: 2026-06-05/06, Maxforms.) Avoid: review never commits to the PR branch;
  evidence goes to the orphan evidence branch only.
- **Filament Select/date-picker resist plain click/type** — use a11y-role finds and
  `$wire.set(...)`/`$wire.mountAction(...)` (the gold patterns in
  `agent-browser-relaticle`). (verified: 2026-05, stable across runs.)
- **Modal fade ~300ms** — don't assert removal immediately; wait on state.
  (verified: 2026-05.)
- **Custom fields are read-only via REST API by design** — a write that is silently
  ignored is current product behavior (documentation gap, not a bug). (verified:
  2026-06, project memory.)

Add new entries as they bite; promote a gotcha to a `regressions.json` entry when it is
a recurrence-capable PRODUCT defect class (gotchas = environment/process; ledger =
product).
