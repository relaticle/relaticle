# Workflow

## Decisions

- For design decisions, present numbered/lettered options with a comparison
  matrix and one clear recommendation. Batch independent questions so they can
  all be answered in a single message; expect one-character answers.

## Guidelines pipeline

- `CLAUDE.md`, `AGENTS.md`, and `GEMINI.md` are compiled artifacts. Edit the
  sources in `.ai/guidelines/relaticle/`, then run `php artisan boost:update`
  and copy `AGENTS.md` to `GEMINI.md` (boost does not write it). Never edit the
  compiled files directly; `tests/Arch/ConventionsTest.php` fails when they drift.

## Releases

- Merge to main and tag only on explicit instruction, never on your own.
- Procedure: merge → `git checkout main && git pull` → confirm local and remote
  parity (`git log origin/main..main` and the reverse are both empty) → tag
  `vX.Y.Z` (minor for features, patch for fixes) → `git push origin <tag>`.

## Issues and milestones

- Milestones are **product themes, never releases**, and never carry a version
  number. Releases are cut from merged PRs and land continuously, so a milestone
  can never track a version: naming them `vX.Y` guaranteed drift, and it did.
  `v3.5.0` shipped the chat rebuild and MCP work while the `v3.5` milestone held
  one unstarted issue that did not ship.
- The live themes are `Infrastructure & Data`, `Integration Platform`,
  `User Experience`, `Billing & Monetization`, and `AI Intelligence`. Each carries
  its scope in its GitHub description. Put a new issue in the theme it belongs to;
  do not re-sync milestones with release tags.
- Milestones carry no due dates. A theme is not time-boxed, and a permanently
  overdue date trains everyone to ignore the field.
- Every issue gets a milestone, an issue type (Bug, Feature, or Task), and a
  Roadmap project status (default Todo). Ask which milestone before choosing one.
  Issue type is set with the GraphQL `updateIssue` mutation and `issueTypeId`;
  `gh issue edit` does not support it.

## External communication

- PR/issue comments, Discord replies, and any other outbound text: show the
  draft and wait for an explicit "post" before publishing.
- Never claim a product capability (in PR bodies, replies, docs) without
  verifying it works in the current codebase. Feature claims must be backed by
  code or a browser repro.
