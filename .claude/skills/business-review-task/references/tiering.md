# Tiering — blast radius → Tier 0–3 (∪ user emphasis)

Tier is computed by `scripts/compute_tier.py` (fed `scripts/classify_diff.py --profile
project-profile.json` output) before any planning. User emphasis can only RAISE it.

## Critical signals (auto-bump to Tier 3)

Union of two sources; any hit forces Tier 3 regardless of breadth:

1. **Generic** (every run): `auth|login|password|session`,
   `payment|billing|charge|invoice|subscription`, `delete|destroy|drop|truncate|purge`,
   `permission|authoriz|policy|acl`, `migration`, `security|secret|token|crypto`.
2. **Relaticle subsystems** (compiled from `profile.sensitive_areas`): `credit`,
   `billing`, `subscription`, `tenant`, `custom_field`, `import`, `chat`, `policy`.

**Sanity-check the classifier, don't worship it** (field evidence: importer paths went
undetected twice; incidental `auth()` tokens over-fired once). If the computed tier
contradicts your read of the diff, re-tier manually and record both the computed and
chosen tier + one-line rationale in the plan frontmatter.

## User emphasis (the override that only raises)

| Signal in the user's words | Tier floor |
|---|---|
| "100,000 customers", "deploy to prod", "stress", "every single angle/detail", "end-2-end" | 3 |
| "deeply", "carefully", "as many scenarios as possible" | 2 |
| "quick", "smoke", "sanity" | cap at 1 (cap, not floor — never below a critical signal) |

A critical signal still wins over a requested cap; say so rather than silently obeying.

## Tier table

| Tier | Trigger | Fleet | Depth |
|---|---|---|---|
| **0** | copy/string tweak, dep bump, no behavior change | 1 in-process pass (no spawn) | touched journey: happy + 1 obvious sad path |
| **1** | one surface, 1–2 AC, no critical signal | 1–2 personas, inline | touched journeys happy + sad; verify any bug |
| **2** | multiple surfaces, or 3+ AC | 3 personas (distinct archetypes) | full subgraph + seams + coverage-critic loop |
| **3** | any critical signal, wide multi-subsystem diff, or production-stakes language | 3–5 incl. `integrity-breaker` | Tier 2 + the **100k contract** (SKILL.md) |

Concurrency cap ≤ 3 live browser sessions always; excess personas queue. Tier 0/1 stay
**in-protocol** — same skeleton (env discovery → preflight → walk → honest frontier →
report), minutes not hours. Scaling down gracefully is what keeps the protocol alive;
abandoning it for "quick checks" is how regressions slip through.

The tier + rationale (computed, emphasis, chosen) are surfaced to the user before the
fleet starts.
