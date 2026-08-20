---
name: spatie-javascript
description: Apply Spatie's JavaScript coding standards for any task that creates, edits, reviews, refactors, or formats JavaScript or TypeScript code; use for variable declarations, comparisons, functions, destructuring, and Prettier configuration to align with Spatie's JS conventions.
license: MIT
metadata:
  author: Spatie
---

# Spatie JavaScript Guidelines

## Overview

Apply Spatie's JavaScript coding standards to keep JS/TS code consistent and readable.

## When to Activate

- Activate this skill for any JavaScript or TypeScript coding work.
- Activate this skill when working on `.js`, `.ts`, `.jsx`, `.tsx`, or `.vue` files.
- Activate this skill when configuring Prettier for a project.

## Scope

- In scope: JavaScript, TypeScript, Vue single-file components, Prettier configuration.
- Out of scope: PHP, Laravel, CSS-only files, server configuration.

## Workflow

1. Identify the JavaScript, TypeScript, Vue, or Prettier artifact being changed.
2. Read `references/spatie-javascript-guidelines.md` and focus on the relevant sections.
3. Apply Prettier configuration first, then the language rules relevant to the change.

## Core Rules (Summary)

- Use four-space indentation, a 120-character print width, and single quotes.
- Prefer `const`, use `let` only for reassignment, and never use `var`.
- Avoid abbreviated variable names except in clear single-line arrow functions.
- Always use strict equality and cast values before comparing different types.
- Use `function` for declarations and arrow functions for anonymous or terse functions when appropriate.
- Keep functions pure, limit `this`, and use shorthand object methods.
- Prefer object and array destructuring.

## References

- `references/spatie-javascript-guidelines.md`
