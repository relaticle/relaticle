# Spatie JavaScript Guidelines (Reference)

## Prettier Configuration

- Indentation: 4 spaces (via `.editorconfig`, not Prettier default of 2)
- Print width: 120 characters (not Prettier default of 80)
- Quote style: single quotes

## Variable Declarations

- Prefer `const` over `let`. Only use `let` when a variable will be reassigned.
- Never use `var`.
- Reassigning object properties is fine with `const` — the reference is not reassigned.

## Variable Names

- Don't abbreviate variable names. Use full, descriptive names.
- Exception: abbreviations are allowed in single-line arrow functions where the context is obvious.

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

- Terse, single-line functions may use arrow syntax.
- Anonymous functions should use arrow syntax unless they need access to `this`.
- Higher-order functions may use arrow syntax when it improves readability.
- Keep functions pure and limit the use of `this`.

### Object Methods

- Use shorthand method syntax:

```javascript
// Good
const object = {
    handleClick(event) {
        // ...
    },
};

// Avoid
const object = {
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
