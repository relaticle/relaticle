---
paths:
  - 'app/Livewire/**'
  - 'resources/views/**'
---

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

## Alpine x-data quoting (verified 2026-08-23)

- A double quote anywhere inside a double-quoted `x-data`/`x-on` attribute
  silently truncates the attribute and kills the whole component (the only
  symptom is an "X is not defined" Alpine expression error downstream — the
  chat sidebar rendered zero conversations from exactly this). Selector
  strings inside Alpine attributes must use quoteless attribute selectors:
  `closest('[role=dialog]')`, never `closest('[role="dialog"]')`.

## Table header alignment

- The UA stylesheet centers `th`; a table-level `text-start`/`text-left`
  utility never overrides it (inheritance loses to element defaults). Put
  `text-start` on the `th` itself or headers float mid-column over
  left-aligned cells.
