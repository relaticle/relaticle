# Evals — failure-mode regression locks

Each fixture freezes a NAMED historical failure so the deterministic spine can never
regress to it silently:

| fixture | locks | expected |
|---|---|---|
| 01-degraded-channel | Maxforms #196: verdict off a dead browser channel | `label: blocked`, never ai-approved/rejected |
| 02-happy-path-only-trap | sad path 500s and verifier confirms | `label: ai-rejected`, blocker confirmed |
| 03-faked-breadth-trap | empty frontier on a broad diff | `frontier_suspicious: true` |
| 04-needs-human-frontier | bare needs-human label with no decision | `decision_needed` present, frontier items carry `how_to_close` |
| (self-test) check_regressions.py --test | Relaticle 209→326 missed-regression (Sentry 127436080): a PR-326-shaped diff MUST match REG-001 and an unscheduled match MUST fail the plan gate | T1/T2 in the script |

Run everything:

```bash
python3 scripts/run_evals.py                 # fixtures (aggregator-level locks)
python3 scripts/check_regressions.py --test  # ledger matcher + plan-gate locks
python3 scripts/classify_diff.py --test && python3 scripts/compute_tier.py --test \
  && python3 scripts/validate_plan.py --test && python3 scripts/aggregate_verdicts.py --test
```

All must pass after ANY skill edit (not quarterly — per edit). Promote interesting real
runs to fixtures with `scripts/promote_to_fixture.py`. The LLM drift-check
(`scripts/run_drift_check.py` + `grader-rubric.json` + `agents/grader.md`) grades REVIEW.md
prose quality — run it after substantive report-shape changes.
