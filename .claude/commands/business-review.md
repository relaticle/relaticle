Task: Business-review local work or a Relaticle pull request end-to-end in a real browser.

## Input

The user provides EITHER:
- Nothing → defaults to **local mode**: current branch vs `main`, committed changes only
- `--working-tree` → local mode including uncommitted changes
- A GitHub PR number on `relaticle/relaticle` (e.g. `209`, `#209`, or a full PR URL) → **PR mode**
- `--describe "<text>"` → no diff input; acceptance criteria come from the text
- Optionally, `--publish` (PR mode only) to skip the end-of-run prompt and post directly to the PR
- Optionally, `--no-prompt` to suppress the end-of-run prompt

If the user passes a GitHub URL or `#<num>`, treat it as `--pr <num>`.

Examples:
- `/business-review` — local, current branch vs main, end-of-run prompt
- `/business-review --working-tree` — local, includes uncommitted changes
- `/business-review --pr 209` — PR mode, end-of-run prompt
- `/business-review --pr 209 --publish` — PR mode, auto-post to GitHub
- `/business-review --describe "User can pick EUR currency on opportunities"` — describe mode

## What to do

1. Parse the user input. Resolve any PR number (strip `#`, extract from URL if needed). Detect `--publish`, `--working-tree`, `--describe`, `--no-prompt` flags anywhere in the input.
2. Invoke the `business-review-task` skill via the `Skill` tool:
   - `skill: business-review-task`
   - `args: "<the parsed flags, normalized>"`
     - Local default: `args: ""`
     - With working tree: `args: "--working-tree"`
     - PR mode: `args: "--pr <N>"`
     - PR mode + publish: `args: "--pr <N> --publish"`
     - Describe mode: `args: "--describe \"<text>\""`
     - Append `--no-prompt` when present

The skill is the single source of truth for the workflow — do not paraphrase or shortcut its stages.

## Scope

This is browser verification of intended behavior — NOT code review, security review, or scope-creep analysis. For those, point the user at:
- `/code-review` — Anthropic's official multi-agent confidence-scored code review
- `/review` — gstack pre-landing structural review
- `/deep-review` — comprehensive multi-agent PR review
- `/pr-fix-workflow` — review + fix loop

## Local backing store

Review artifacts (plan, requirements, diff, screenshots, traces, recordings) land in:
- Local mode: `.context/reviews/local/`
- PR mode: `.context/reviews/<pr-number>/`

Both are gitignored. Leave them in place after the run for post-hoc inspection by the next AI session or human reviewer.

## Rules

- Never run `migrate:fresh` or `migrate:refresh` during a review.
- Stay on the review branch when finished; the user switches back manually.
- Test records created during the run use the `br-<pr>-` (PR mode) or `br-local-<short-sha>-` (local mode) prefix.
- Default is the end-of-run prompt; only post to GitHub when `--publish` is explicit (PR mode) or the user confirms at the prompt.
- Pure local mode never posts to GitHub without an explicit PR number supplied at the prompt.
