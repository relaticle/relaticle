---
name: spatie-version-control
description: Apply Spatie's version control conventions when creating commits, branches, pull requests, or managing Git repositories; use for naming repos, writing commit messages, choosing branch strategies, and merging code.
license: MIT
metadata:
  author: Spatie
---

# Spatie Version Control Guidelines

## Overview

Apply Spatie's Git and version control conventions for consistent repository management.

## When to Activate

- Activate this skill when creating commits, branches, or pull requests.
- Activate this skill when naming new repositories.
- Activate this skill when deciding on branching or merging strategies.

## Scope

- In scope: Git operations, repository naming, branch naming, commit messages, merge strategies.
- Out of scope: Code style, deployment pipelines, CI/CD configuration.

## Repository Naming

### Site source code

Use the main domain name in lowercase, without `www`:
- Good: `spatie.be`
- Bad: `https://www.spatie.be`, `www.spatie.be`, `Spatie.be`

### Subdomains

Include the subdomain in the repo name:
- Good: `guidelines.spatie.be`
- Bad: `spatie.be-guidelines`

### Packages and other projects

Use kebab-case:
- Good: `laravel-backup`, `spoon`
- Bad: `LaravelBackup`, `Spoon`

## Branches

### Initial development

- Maintain `main` and `develop` branches.
- Commit through `develop`, not directly to `main`.
- Feature branches are optional; if used, branch from `develop`.

### Live projects

- Delete the `develop` branch.
- All commits to `main` must come through feature branches.
- Prefer squashing commits on merge.

### Branch naming

- Use lowercase letters and hyphens only.
- Good: `feature-mailchimp`, `fix-deliverycosts`, `updates-june-2016`
- Bad: `feature/mailchimp`, `random-things`, `develop`

## Commits

### Message format

- Always use **present tense**.
- Good: `Update deps`, `Fix vat calculation in delivery costs`
- Bad: `wip`, `commit`, `a lot`, `solid`

### Granularity

- Prefer small, focused commits over large ones.
- Use `git add -p` for interactive staging to create granular commits.

## Merging

- Rebase regularly to reduce merge conflicts.
- For deploying feature branches: use `git merge <branch> --squash`.
- If push is denied: use `git rebase` (not merge).

## Pull Requests

- Optional but useful for peer review, merge validation, and historical reference.

---

Source: https://spatie.be/guidelines/version-control