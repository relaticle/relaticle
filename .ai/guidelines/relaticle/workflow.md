# Workflow

## Decisions

- For design decisions, present numbered/lettered options with a comparison
  matrix and one clear recommendation. Batch independent questions so they can
  all be answered in a single message; expect one-character answers.

## Guidelines pipeline

- `CLAUDE.md`, `AGENTS.md`, and `GEMINI.md` are compiled artifacts — edit the
  sources in `.ai/guidelines/relaticle/`, then run `php artisan boost:update`
  and copy `AGENTS.md` to `GEMINI.md` (boost does not write it). Never edit the
  compiled files directly; `tests/Arch/ConventionsTest.php` fails when they drift.

## Releases

- Merge to main and tag only on explicit instruction — never on your own.
- Procedure: merge → `git checkout main && git pull` → confirm local and remote
  parity (`git log origin/main..main` and the reverse are both empty) → tag
  `vX.Y.Z` (minor for features, patch for fixes) → `git push origin <tag>`.

## External communication

- PR/issue comments, Discord replies, and any other outbound text: show the
  draft and wait for an explicit "post" before publishing.
- Never claim a product capability (in PR bodies, replies, docs) without
  verifying it works in the current codebase — feature claims must be backed by
  code or a browser repro.
