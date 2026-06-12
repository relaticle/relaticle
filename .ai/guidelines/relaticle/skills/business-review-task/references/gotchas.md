# Gotchas — Named failure modes from real runs

Each entry: symptom + root cause + how to detect + how to avoid.

## Filament Select dropdowns require a specific click sequence

**Symptom:** `agent-browser click '.fi-fo-select-trigger'` opens the dropdown, but subsequent `agent-browser click 'li[data-value="X"]'` does nothing.

**Root cause:** Filament's Select uses Choices.js (or its replacement). The option list is rendered in a different DOM container, not as a child of the trigger.

**Detect:** After clicking the trigger, `agent-browser snapshot -i` shows the options under `.fi-dropdown-list` or `.choices__list--dropdown`, not under the original Select.

**Avoid:** Use `agent-browser find role option <option-text> click` instead of selector-based clicks. The accessibility tree finds the option regardless of where Filament renders it.

## Modal cancel button is two clicks away

**Symptom:** Pressing Escape or clicking `.fi-modal-close-action` doesn't close the modal cleanly; subsequent assertions fail because the modal is still in DOM.

**Root cause:** Filament modals fade out over ~300ms; the DOM element persists during animation.

**Detect:** `agent-browser is visible '.fi-modal-window'` returns true even after click.

**Avoid:** After closing, `agent-browser wait --load networkidle` AND `agent-browser wait '.fi-modal-window:not([data-state="open"])' 2000`. Or just navigate away and back.

## Stale browser session from prior review

**Symptom:** First case after switching branches sees logged-out state even though prior review's session existed.

**Root cause:** Server-side session was tied to the old branch's database state; switching branches without re-seeding invalidates the session row.

**Detect:** First case in a batch fails with a redirect to `/login`.

**Avoid:** First case in each batch should be `agent-browser open "$RELATICLE_URL/app/login"` + fresh login, even if a session "should" exist.

## AI credit balance bottoms out mid-review

**Symptom:** Test exercises `packages/Chat/` AI feature; first prompt succeeds, second returns "insufficient credits."

**Root cause:** Local seeder `LocalSeeder::topUpAiCreditsForLocalTeams()` tops balances to 1,000,000 on every `php artisan db:seed` run, but balances drain during interactive testing.

**Detect:** `AiCreditBalance::where('team_id', $teamId)->value('credits_remaining')` returns low number.

**Avoid:** Re-run `php artisan db:seed --class=LocalSeeder` before a Chat-heavy review, OR top up directly via tinker:

```bash
php artisan tinker --execute '
\App\Models\AiCreditBalance::query()->update(["credits_remaining" => 1000000]);
'
```

## Custom fields written via API are silently dropped

**Symptom:** API consumer POSTs a Company with `custom_fields: {...}`; record persists but custom fields are missing on subsequent GET.

**Root cause:** Per project memory, custom fields are intentionally read-only via API today. The write attempt is silently ignored (no 422), which is current product behavior — flag as a documentation gap, NOT a bug.

**Detect:** Compare POST payload vs GET response — `custom_fields` block in POST is gone in GET.

**Avoid:** Don't treat this as a real failure unless the API docs claim writability. Write a Finding with "Currently unsupported — documentation gap" instead.

## CSRF token mismatch after long-idle session

**Symptom:** Form submission returns 419 mid-review.

**Root cause:** Browser session is old; `XSRF-TOKEN` cookie expired or rotated.

**Detect:** Network trace shows POST returning 419 with `{"message": "CSRF token mismatch."}`.

**Avoid:** `agent-browser reload` before the offending case. Livewire pulls the fresh token.

---

Add new gotchas as you encounter them. Keep each entry concise. The pattern: symptom → root cause → how to detect → how to avoid.

---

## Niche workflows

### Batch mode (multi-PR session)

When reviewing several PRs in the same Claude session:

**Do NOT delegate to a general-purpose subagent for the whole flow.** Subagents see a stale `gitStatus` snapshot from session start instead of the current live git state. They'll report the wrong branch and refuse to start on a "dirty" tree even when the main shell is clean. Run sequentially in the main session, or open separate Polyscope workspaces for parallel reviews.

The Stage 2 parallel subagents (diff-analyzer + intent-analyzer) at planning start are the only allowed parallel dispatch — they're pure-read and don't depend on branch state.

**Write large outputs to disk, read narrow slices.** `gh pr diff` for a big PR can be 30KB+. Pipe to `$REVIEW_DIR/pr-diff.patch` and read narrow ranges with `sed -n 'X,Yp'` rather than slurping the whole patch into context.

**Skip browser aggressively for backend-only PRs.** The `mode: pest-only` path saves the biggest slice of context per review.

**Reuse the browser session across runs.** Session name is constant (`AB_SESSION="relaticle-review"`), so login state persists across reviews — no need to re-authenticate.

**Idempotency marker still applies per-PR.** Each PR has its own `br-sha:<short>` based on `headRefOid`. Re-running on the same SHA stops at Stage 1. Re-running after a force-push generates a new SHA → new review.

For local-mode batches, batch concerns are smaller (you're not switching branches), but the same "don't delegate the orchestrator" rule applies.

### Deferred (visual baselines, routine packaging, image upload)

**Visual baseline diffing.** `agent-browser diff screenshot --baseline` is available natively. The blocker isn't the capability, it's the baseline maintenance. Future shape: CI job on `main` merges captures baselines per Filament page; baselines committed under `tests/baselines/<slug>.png`; Stage 2 calls `agent-browser diff screenshot --baseline ...` per case. Why deferred: maintaining baselines requires a CI job + refresh workflow outside the skill's scope.

**GitHub Routine packaging (auto-trigger on `pull_request`).** Routines are a Claude Code Web feature that can fire on `pull_request: opened|synchronize`. Packaging this skill as a Routine would auto-fire business reviews on new PRs. Why deferred: Routines are cloud-Web feature; access depends on Anthropic plan. Investigate after the skill stabilizes.

**Inline image rendering in PR comments.** The current design posts text-only because there's no clean way to inline-render images in a private-repo PR via automation. Future possibilities: `gh-attach` browser-cookie tool, or `agent-browser` upload via the PR composer. Why deferred: text-only removes a leak risk and is simpler. Add only if reviewers complain.

**Skill versioning.** A `version:` field in SKILL.md frontmatter + surfacing it in the comment footer would let reviewers identify which skill version produced a given review. Not needed until the skill has multiple actively-deployed versions.

### Local-mode re-verify after a fix

When a downstream AI fixes a finding and you want to re-verify:

1. The fix commit produces a new `HEAD` → new `$SHORT_SHA`. With the current local-mode layout (`.context/reviews/local/`), the prior review is overwritten in place.
2. `.context/reviews/local/LATEST.txt` updates to point at the new run.
3. The `br-sha:<short>` line in `REVIEW.md`'s footer identifies the snapshot reviewed — no separate version tag needed.
