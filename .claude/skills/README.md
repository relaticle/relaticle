# Relaticle agent skills

Repo-local skills for Claude Code (and compatible agents). Loaded on demand — only the
one-line description rides in session context.

## The business-review suite

| Skill | Role |
|---|---|
| `business-review` | **The active reviewer (v3)** — panel-of-QAs engine: environment discovery, capability preflight, blast-radius tiering, journey synthesis, persona walks, regression-ledger sweep, adversarial verification, substance-gated verdict, fix mode. Invoked via `/business-review`. |
| `agent-browser-relaticle` | Browser cookbook for this app (panel URL derivation, login flows, Filament/Livewire `$wire` patterns, env hazards). All facts are dated cached hints that self-heal. |
| `screenshot-with-callout` | Evidence-quality screenshot discipline (annotate → verify-crop → shoot → read-back). Vendored from the maintainer's dotfiles so every contributor has it; keep in sync when the upstream copy improves. |

### Prerequisites (machine setup)

- **`agent-browser` CLI** on PATH — the browser automation driver every review uses
  (`agent-browser --help` to verify).
- **Laravel Herd** serving this checkout (`herd sites`), with the seeded local logins
  (`php artisan db:seed --class=LocalSeeder`).
- Python 3 (skill scripts are pure stdlib).

### Quality loop (run after ANY skill edit)

```bash
cd .claude/skills/business-review
python3 scripts/run_evals.py                  # failure-mode fixture locks
python3 scripts/check_regressions.py --test   # ledger matcher + plan-gate locks
for s in classify_diff compute_tier validate_plan aggregate_verdicts sanitize_pr extract_ac promote_to_fixture; do
  python3 scripts/$s.py --test || break
done
```

CI runs the same suite on every PR touching `.claude/skills/**`
(`.github/workflows/skills-evals.yml`).

### Auto-review trigger (optional, not yet enabled)

To run a business review automatically when a PR goes ready-for-review, add a
workflow using `anthropics/claude-code-action` with an `ANTHROPIC_API_KEY` secret and
the prompt `Run /business-review <PR_NUMBER> --publish`. Deliberately not shipped by
default: it spends API budget per PR and needs a repo-secret decision by the maintainer.
