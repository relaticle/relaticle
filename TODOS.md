# TODOs

Deferred work from plan reviews. Each item includes context and reasoning so it can be picked up without the original conversation.

---

## Centralize Marketing Numbers

**What:** Extract hardcoded marketing numbers (MCP tools count, custom field types, test count, authorization layers) into a single config or constants file. All marketing surfaces (hero, features, FAQ, pricing, schema markup, README) should reference this source of truth.

**Why:** The MCP tools count drifted from 20 to 30 as tools were added, but marketing copy wasn't updated. This was caught during a manual audit but could happen again with any number.

**Where numbers appear:** hero.blade.php, features.blade.php, faq.blade.php, community.blade.php, pricing.blade.php, index.blade.php (schema markup + OG description), layout.blade.php (documentation meta), mcp-guide.md, README.md.

**Approach:** Create a `config/marketing.php` with keys like `mcp_tools_count`, `field_types_count`, `test_count`. Reference via `config('marketing.mcp_tools_count')` in Blade templates. For README.md (not rendered by Laravel), keep manual but add a comment noting the config source.

**Effort:** S (CC: ~15min) | **Priority:** P2 | **Depends on:** Nothing

---

## Document the 5-Layer Authorization Model

**What:** The marketing claim "5-layer authorization" is used across multiple surfaces but the actual 5 layers are never explicitly defined. Based on code analysis, the layers appear to be: (1) User authentication (Sanctum/Fortify), (2) Team membership, (3) Team roles, (4) Eloquent policies, (5) Multi-tenancy scoping. This should be documented.

**Why:** If a technical evaluator asks "what are the 5 layers?", there's no answer in the docs. Undocumented marketing claims erode trust with developer audiences.

**Where to document:** Either the developer guide (developer-guide.md) or a dedicated security/architecture section in the getting-started guide.

**Effort:** S (CC: ~10min) | **Priority:** P2 | **Depends on:** Nothing
