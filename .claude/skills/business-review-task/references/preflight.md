# Capability preflight + circuit breaker + the `blocked` state

Read before any planning. Runs immediately after environment discovery
(`environment.md`), against the **derived** panel URL — never a hardcoded one.

## 1. Why

The canonical failure (Maxforms PR #196): the browser channel was degraded, no live test
happened, yet every shape-gate passed vacuously and a verdict shipped. **Absence of
evidence must never pass the evidence gate.** A run that cannot drive the browser never
emits a verdict label — it stops, says so, and asks for direction.

## 2. The 5-step smoke (channel, not app; before any planning)

Against `$APP_PANEL_URL` from `$REVIEW_DIR/project-profile.json`:

1. **Open the login page** — `agent-browser open "$APP_PANEL_URL/login"`.
   PASS: page loads. FAIL: command errors/hangs/returns nothing.
2. **Read back a known element** — `agent-browser snapshot -i`.
   PASS: interactive refs incl. the email input. FAIL: empty/blank snapshot.
3. **Screenshot to a temp path** — `agent-browser screenshot /tmp/br-preflight.png`.
   PASS: file written. FAIL: error or nothing written.
4. **Read the screenshot back** (Read tool on the PNG) and actually look at it.
   PASS: a real image showing the login page. FAIL: 0-byte/blank — the agent cannot
   see its own output.
5. **Type into the email field and confirm it registered** — `fill` then `get value`,
   targeting the ref the step-2 snapshot gave you (`@eNN`) or the snapshot-derived
   visible input — NEVER a guessed CSS selector. PASS: read-back **equals the exact
   string typed**. FAIL: empty, unchanged, or ANY OTHER VALUE.
   A read-back that differs from what you typed is a fail to investigate, not a pass to
   rationalize (incident 2026-06-12: `input[name="email"]` matched a HIDDEN
   `laravel-login-link` field; the fill hung the daemon, `get value` returned that
   field's pre-existing default, and the mismatched read-back was waved through as
   "autofill" — the same wrong selector then cost the login flow 15 minutes later).
   Hidden-field shadowing is the specific trap: confirm the element is visible
   (`agent-browser eval` on `offsetParent !== null`) before trusting name-attribute
   selectors on login pages.

The smoke tests the channel, **not authentication** — it stops at "the field accepts
input". Establishing a session is setup (`environment.md` §2, `browser-truth.md` §3b).
A failing login is a **credentials Context gap → at worst `ai-needs-human`, never
`blocked`**.

Also confirm session isolation support once: `agent-browser session list`. If
`--session` is unsupported, fall back to **sequential** persona execution and say so in
the report (documented degraded mode, not a silent default).

**ANY step returning malformed/absent/un-read-backable output → the channel is down →
§5 `blocked`.** Before declaring it down, spend ONE bounded attempt distinguishing a
fixable env defect: stale build (`npm run build`), config cache (`php artisan
config:clear`), wrong websocket host, hung daemon (`agent-browser` restart). A defect
you can fix in setup is setup; a channel you cannot fix is `blocked`
(verified: 2026-06-10 — a "degraded channel" on PR 322 turned out to be a stale JS
bundle + wrong-host websocket, cleared by a rebuild).

## 3. The discriminator (the crux)

Tell apart **"the app is broken"** (finding — report it) from **"the agent cannot
test"** (incapacity — halt):

> **Can the agent get ANY well-formed response back at all?**

- Well-formed response showing a broken app (a readable 500 page, a validation error on
  clean load, a clean 404 where content was expected) → **FINDING**. Continue; route it
  through adversarial verification.
- Malformed/absent/un-read-backable response, or the agent cannot see its own output
  (0-byte screenshots, empty snapshots, fill that never registers) → **INCAPACITY** →
  halt → `blocked`.
- Login submits but credentials are wrong / no usable login exists → **credentials
  Context gap** (never `blocked` — the channel works).

When undecidable, treat as incapacity: a verdict off a dead channel is the exact harm
this file prevents.

## 4. Circuit breaker (mid-run)

Track consecutive degraded signals: blank command output where structure was expected,
0-byte/unreadable screenshot, snapshot unchanged after an action that must change the
page. **2 in a row → trip.** On trip: stop spawning/continuing cases, finalize with
what is already confirmed (a partial). One isolated signal is not a trip — reset on the
next well-formed response. Personas and the verifier carry the same rule and set
`"channel_degraded": true` in their findings for the orchestrator.

## 5. The `blocked` state

On preflight fail, breaker trip, or (Tier 3) a failed env-realism gate:

1. Set **`channel: "degraded"`** in the plan frontmatter (env-gate blocks at Tier 3 use
   `blocked_reason` with the failed gate) — `aggregate_verdicts.py` derives
   **`label: blocked`**, which overrides everything.
2. Surface any code-trace glimpses, each stamped **`NOT browser-verified`**.
3. Escalate with exactly three options and STOP: **retry now** / **fix env + resume**
   (name the fix: the `how_to_close` for each failed gate) / **abort**.
4. Never write a verdict label, never publish, never apply a GitHub label. `blocked` is
   the absence of a verdict, not a verdict.
