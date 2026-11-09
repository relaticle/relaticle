---
paths:
  - 'packages/Cloud/**'
---

# Cloud package

`packages/Cloud` follows the rules core does. Boost writes `.ai/rules/boost/*` from its own guidelines, and `boost:update` rewrites their globs, so those files cannot name Cloud paths. This file points to them.

| Cloud path | Rule file |
| --- | --- |
| `packages/Cloud/src/**/Http/**`, `packages/Cloud/routes/**` | `.ai/rules/boost/http-routes.md` |
| `packages/Cloud/src/**/Livewire/**`, `packages/Cloud/resources/views/**` | `.ai/rules/boost/livewire-views.md` |
| `packages/Cloud/tests/**` | `.ai/rules/boost/tests.md` |

The boundary rules for Cloud are in `.ai/guidelines/relaticle/architecture.md`, under "The cloud package".
