# Business-Review Eval Harness

Hybrid evaluation: snapshot assertions gate every skill PR (cheap, deterministic); LLM-graded drift detection runs quarterly for prose-quality regressions (expensive, manual).

**Status (Relaticle port):** the harness scripts work, but `fixtures/` is empty. The original Maxforms fixtures referenced product surfaces (public forms, the form-builder package) that don't exist in Relaticle and would produce misleading green results. Capture real Relaticle fixtures via `promote_to_fixture.py` as you run reviews — aim for a small spread (1 narrow bugfix, 1 wide feature, 1 inferred-AC pause, 1 import-wizard run, 1 chat run).

## Running the snapshot suite

```bash
python3 .ai/guidelines/relaticle/skills/business-review-task/scripts/run_evals.py
```

Target runtime: < 30 seconds. Exits 0 on all-pass (including the zero-fixture case), non-zero on any failure. With no fixtures, `run_evals.py` simply reports `0 fixtures, all pass`.

## Adding a new fixture

After a real review you ran turns out interestingly right or wrong:

```bash
python3 .ai/guidelines/relaticle/skills/business-review-task/scripts/promote_to_fixture.py <PR_NUM> <fixture-name>
```

This copies `.context/reviews/<PR_NUM>/` inputs into `evals/fixtures/<NN>-<fixture-name>/inputs/`, scaffolds `expected.json` from the actual output (you formalize), and opens `description.md` for notes.

Cap the suite at ~10 fixtures.

## Running the LLM drift check (quarterly)

```bash
python3 .ai/guidelines/relaticle/skills/business-review-task/scripts/run_drift_check.py prepare
# → writes evals/drift-prompts/*.md

# In a Claude Code session, paste each prompt into a general-purpose subagent
# (or use the Agent tool). Save responses to evals/drift-responses/<fixture>.md

python3 .ai/guidelines/relaticle/skills/business-review-task/scripts/run_drift_check.py collect
# → writes evals/drift-report.md
```

Review the drift report for criteria scoring below 3/5. Those are signals to tighten SKILL.md, the subagent prompts, or the reference files.

## Limitations to be honest about

- Browser-case fixtures stub out Phase 6's actual `agent-browser` interactions and replay pre-baked `verdict.json` files. The harness tests planning + aggregation logic, NOT browser interaction correctness. Real browser behavior is verified by manual runs against real PRs.
- Snapshot assertions catch label changes and missing/forbidden substrings. They miss prose-quality drift — the LLM grader fills that gap, but only on manual quarterly runs.
- Fixtures freeze a point-in-time output. When the skill's expected output evolves legitimately (new section, new rubric), the fixtures' `expected.json` files need updating. Document the change in the fixture's `description.md`.

## Fixture directory shape

```
evals/fixtures/<NN>-<name>/
├── inputs/                       # Pre-positioned $REVIEW_DIR contents
│   ├── untrusted/{title,body}.txt
│   ├── untrusted/comments/
│   ├── pr-diff.patch
│   ├── pr-files.txt
│   ├── pr-context.json
│   ├── plan.md                   # Optional — for fixtures testing post-Phase-5 logic
│   ├── acceptance-criteria.json  # Optional — same
│   └── case*/verdict.json        # Optional — for testing aggregation logic
├── expected.json                 # Snapshot assertions
└── description.md                # Human notes on intent
```
