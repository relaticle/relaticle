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
- Activate this skill when configuring Prettier or ESLint for a project.

## Scope

- In scope: JavaScript, TypeScript, Vue single-file components, Prettier/ESLint configuration.
- Out of scope: PHP, Laravel, CSS-only files, server configuration.

## Prettier Configuration

- Indentation: 4 spaces (via `.editorconfig`, not Prettier default of 2)
- Print width: 120 characters (not Prettier default of 80)
- Quote style: single quotes

## Variable Declarations

- Prefer `const` over `let`. Only use `let` when a variable will be reassigned.
- Never use `var`.
- Reassigning object properties is fine with `const` — the reference is not reassigned.

## Variable Names

- Don't abbreviate variable names in multi-line functions. Use full, descriptive names.
- Exception: single-line arrow functions where context is obvious.

```javascript
// Good — full names in multi-line functions
function saveUserSession(userSession) {
    // ...
}

// Acceptable — short name in single-line arrow
userSessions.forEach(s => saveUserSession(s));
```

## Comparisons

- Always use `===` (strict equality). Never use `==`.
- If unsure of the type, cast it first:

```javascript
const number = parseInt(input);

if (number === 5) {
    // ...
}
```

## Functions

### Function Declarations

- Use the `function` keyword for named functions to clearly signal it's a function.

### Arrow Functions

- Use for terse, single-line operations.
- Use for anonymous callbacks.
- Use in higher-order functions when it improves readability.
- Don't use arrow functions when you need `this` context (e.g., jQuery event handlers).

### Object Methods

- Use shorthand method syntax:

```javascript
// Good
const obj = {
    handleClick(event) {
        // ...
    },
};

// Avoid
const obj = {
    handleClick: function(event) {
        // ...
    },
};
```

## Destructuring

- Prefer destructuring over manual property/index access:

```javascript
// Good
const [hours, minutes] = '12:00'.split(':');

// Good — configuration objects with defaults
function createUser({ name, email, role = 'member' }) {
    // ...
}

// Avoid
const parts = '12:00'.split(':');
const hours = parts[0];
const minutes = parts[1];
```

---

Source: https://spatie.be/guidelines/javascript