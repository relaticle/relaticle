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

## Workflow

1. Identify the Git operation or repository convention involved.
2. Read `references/spatie-version-control-guidelines.md` and focus on the relevant sections.
3. Apply the project-stage rules before branch, commit, merge, or cleanup guidance.

## Core Rules (Summary)

- Name site repositories after their lowercase naked domain and other repositories with kebab-case.
- Keep `main` stable and deployable after launch, and remove stale branches.
- Use `develop` during initial development; use feature branches once a project is live.
- Use only lowercase letters and hyphens in branch names.
- Write descriptive, present-tense, granular commits.
- Rebase regularly and generally squash feature branches when merging.
- Treat history-rewriting and destructive Git commands with the cautions in the reference.

## References

- `references/spatie-version-control-guidelines.md`
