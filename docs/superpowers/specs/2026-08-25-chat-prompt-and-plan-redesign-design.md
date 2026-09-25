# Chat prompt and plan redesign

Date: 2026-08-25
Status: draft, awaiting review
Target model: `claude-sonnet-4-6` (every `meta.model` row in production)

## 1. Why this exists

An audit of all 347 production chat conversations (2026-06-08 to 2026-08-25, 2,040 messages,
179 teams) plus a prompt audit against Anthropic's own `prompt-audit` and `agent-design`
references produced three findings that a prompt rewrite alone would not fix.

**The prompt is not too long, it is duplicated.** Since #507 shipped on 2026-08-19, 89.5% of
input tokens are served from cache and W35's median `prompt_tokens` is 4. The 4,200-token static
prompt is a cache read at roughly 0.1x on nine turns in ten. Cutting it saves almost nothing.

**Our failure mode is under-specification, not disobedience.** #345 added exactly
`- No celebratory emoji` on 2026-06-14. That text survived unchanged until #506. Emoji usage ran
27-33% for two months because the emoji in use were decorative and status markers, not
celebratory ones. The model followed the narrow rule narrowly. #506 widened it to "No emoji of
any kind: not celebratory, not decorative, not as status or priority markers" and usage went to
0/59. Measured the same way, the markdown-table ban went 13% to 1%, the result-set heading ban 3%
to 1%, and the tool-narration ban 2% to 0%. Rules work when they say what we mean.

**We test the prompt's wording and nothing tests the model's behaviour.**
`tests/Feature/Chat/CrmAssistantInstructionsTest.php` is green with 16 tests and 38 assertions.
Thirty-five of them pin exact prompt phrases. Not one could have caught a defect in this audit,
and the suite cannot distinguish a deliberate guard from accidental current wording. That gap is
why the audit initially misread two intentional, tested guards as regressions.

## 2. Principles

1. Do not optimise for length. Find dated instructions instead. This is the Anthropic audit's
   own prime directive and our caching data makes it concrete.
2. Say exactly what you mean, once, at normal volume. Never add force to a rule that is being
   obeyed literally; say the thing you actually meant.
3. Anything the code already knows must not be restated in prose. The project rule against
   storing one fact in two places applies to the prompt.
4. A prohibition survives only if its failure reproduces on `claude-sonnet-4-6` and no code path
   can enforce it.
5. Context is never cruft. Field Truth, No Dead Ends, the untrusted-tool-result rule, the
   approval contract, and the citation contract stay.

## 3. Scope

In: the `CrmAssistant` static and dynamic prompt; `TurnContinuationService::PROMPT` and the
`[approval]` marker text; `ConversationTitler`; the 41 tool descriptions; the six defects in
section 6; the test strategy in section 7; the compliance harness in section 7.3.

Out: anything outside chat. The model does not change.

## 4. Prose that becomes code

| Today | Becomes | Rationale |
|---|---|---|
| Rule 3's hand-written map of which tools render blocks, naming 8 tools | Generated from the tool registry when the prompt is assembled | Duplicated state; disabling a tool makes the prompt lie |
| ~11 tool class names in prose | Removed; tools describe themselves | Anthropic audit Group 3; dangling references are silent bugs |
| Rule 10, ~300 words of pagination phrasing | List payloads carry a rendered `page_summary` the model quotes verbatim | It is a fact, not a judgment, and it grew incident by incident |
| Rule 12, never expose raw record IDs | Renderer strips or links IDs; prompt keeps one sentence of rationale | Code cannot forget |
| Rule 9, `{{block:N}}` counting including invisible calls | Marker resolved server-side from the real call list | The model is doing arithmetic we already have |

Stays prose: Field Truth, No Dead Ends, the approval contract, the untrusted-content rule, the
citation contract, and a one-line role statement.

Gets more text, not less: the 41 tool descriptions. Under-description is the documented common
failure and detailed descriptions are the largest single factor in tool performance. Our own data
agrees: `ListTasksTool` gained an assignee filter and a precise description in #490 and the
user complaint stopped the same day.

Expected result: 16 numbered rules plus 9 sections becomes roughly 6 sections of judgment and
contract. The reduction is a consequence, not a target.

## 5. References and batching

### 5.1 The conflict

Prompt line 27 tells the model to batch every record of one type into a single call. Line 29
tells it to chain writes with `$ref:<pending_action_id>`. Line 30 forbids a `$ref` pointing at a
batched proposal. The model reliably generates the illegal combination because two of the three
rules invite it.

`PlanReferenceValidator` also rejects a reference whose target is already approved. This is a
genuine race: the model emits several tool calls in one turn and the user can approve card 1
while card 3 is still being validated. That is exactly what happened on 2026-08-24 at 22:25:52,
twelve minutes after #506 deployed.

Neither restriction is a regression. Both are deliberate and both have passing tests
(`it('rejects a reference to a multi-record proposal')`, `it('rejects a reference to an already
decided proposal')`). Both self-recover: the model proposes the records unlinked and links them
with follow-up updates, and every record in the affected turns was created and approved. The
user-visible cost is extra approval round-trips, not failure.

### 5.2 Resolution

**Indexed references.** Extend the syntax to `$ref:<pending_action_id>#<index>`, addressing one
record inside a batched proposal. No new storage is required: `approveItem()` already writes
`result_data['items'][$index]['id']` as a sparse map, and `ProposalProgress` already reads it.
`PlanReferenceValidator` accepts an indexed reference when the index is within `records` bounds;
`PlanReferenceResolver` resolves it from `result_data['items'][$index]['id']`.

**Approved targets resolve instead of erroring.** When a referenced proposal is already approved
and carries a real record id, `PlanReferenceValidator` rewrites the reference to that id rather
than returning an error. The record exists and the link is the one the user saw on the card.
The error remains for a rejected, expired or superseded target, where no record exists.

**Prompt follow-through.** Lines 27, 29 and 30 are rewritten as one coherent rule that states
batching and chaining together, since the conflict is currently stated twice, once in prose and
once in the validator.

## 6. Remaining defects

| # | Defect | Fix | Severity |
|---|---|---|---|
| 1 | Deactivated custom fields are invisible to the model, producing confidently wrong answers about the workspace's own schema | `CustomFieldsSchemaDescriber` lists inactive fields too, each marked inactive and not settable, so the model can say "Priority exists but is deactivated, reactivate it in settings" instead of "tasks have no priority field" | Correctness. The only user-visible wrong answer in the audit |
| 2 | `ListActivityTool` returns at most 50 rows with no pagination and no date range | Add `page`/`next_page` matching the list tools | User asked three times for data that could not be returned |
| 3 | One no-op record aborts an entire batch update (`BaseWriteUpdateTool` returns from inside the per-record loop) | Collect skipped records, propose the rest, report what was skipped on the card | Extra round-trip, self-recovering |
| 4 | The assistant proposes writes to system-defined custom fields that then fail | Check `isSystemDefined()` before proposing; escort to settings instead | Cosmetic |
| 5 | Proposal TTL is 15 minutes; 16 expirations across 14 conversations | Raise to 24 hours | A proposal is inert until approved, so a short TTL buys no safety |

## 7. Testing

### 7.1 Replace wording assertions with behaviour

The 35 exact-phrase assertions in `CrmAssistantInstructionsTest` are deleted. They pin phrasing,
break on every rewrite, and cannot catch a behavioural regression. Replaced by:

- Behavioural Feature tests per rule that matters, asserting on what the assembled prompt and
  tool payloads make possible, not on their wording.
- The 3 existing negative guards are kept and extended: a small set of assertions that old,
  known-bad wording has not returned.

### 7.2 Regression tests for section 5 and 6

Each defect gets its repro in the file that already owns that class, per the project convention
of extending existing test files:

| Defect | File |
|---|---|
| Indexed and approved references | `tests/Feature/Chat/PlanProposalTest.php` |
| Batch no-op skip | `tests/Feature/Chat/BatchUpdateProposalTest.php` |
| Inactive field visibility | `tests/Feature/Chat/CustomFieldsBridge/SchemaDescriberTest.php` |
| Activity pagination | `tests/Feature/Chat/ListActivityToolTest.php` |
| System-defined field guard | `tests/Feature/Chat/UpdateCustomFieldToolTest.php` |

Two existing tests in `PlanProposalTest.php` assert the behaviour section 5.2 changes and must be
rewritten in the same commit, not deleted: `it('rejects a reference to a multi-record proposal')`
becomes an assertion that a bare batch reference is still rejected while an indexed one resolves,
and `it('rejects a reference to an already decided proposal')` becomes an assertion that an
approved target resolves to its real id while a rejected or expired one still errors.

### 7.3 The compliance harness

The audit harness becomes a committed tool. It measures rule adherence against production
transcripts in `relaticle_analytics`: emoji, markdown tables of records, result-set headings,
tool narration, next-step offers, and any rule added later. It reports a rate per rule per
period, so "did this prompt change help" becomes a number.

It is a reporting tool, not a CI gate. It reads a pseudonymised clone that CI cannot reach, and
its value is the before-and-after comparison a human reads after a deploy.

## 8. Rollout and verification

1. Feature tests for sections 5 and 6, red first. These need only the test database.
2. Prompt restructure, with `CrmAssistantInstructionsTest` rewritten in the same commit.
3. Browser walk on the production-shaped stack: Horizon running, `QUEUE_CONNECTION=redis`,
   Reverb up, the full send to stream to proposal card to approve loop, per the project's chat
   verification rule. This currently requires starting Horizon in this workspace, see section 9.
4. Local quality gate: `pint --dirty`, `rector --dry-run`, `phpstan analyse`,
   `composer test:type-coverage`, then the targeted suites.
5. After deploy, re-run the compliance harness against the next week of transcripts and compare.

## 9. Risks and out-of-band dependencies

**`TurnContinuationService::PROMPT` is load-bearing outside the app.** The analytics filter that
made this audit possible matches that exact string to separate 41 system-injected messages from
979 real ones. Changing the constant without changing the filter silently corrupts every future
audit. Any hunk touching it ships with the filter change.

**Nine test files assert on prompt text.** `grep -rlE "staticInstructions|instructions\(\)" tests/`
before calling any prompt hunk done.

**The local stack cannot currently run the browser leg.** Horizon is not running for this
workspace; the live workers belong to the `hong-kong` checkout and Redis prefixes isolate the
queues, so a chat job queued here would never be picked up. `DB_DATABASE=relaticle_app` is also
shared with that checkout. Both must be resolved before step 3 of the rollout.

**The post-fix samples are thin.** The 0/59 emoji result and the 89.5% cache figure rest on
windows dominated by founder dogfooding. Treat them as directional.

**No production error signal was available.** Relaticle uses Sentry; the connected Flare MCP
holds other projects and the Sentry MCP needs interactive OAuth. Three defects a user reported
in-chat on 2026-08-10 (task assignee not saved on approve, "Error while loading page" when
approving a task carrying relations, people dropdown not preloading) remain unreproduced rather
than absent.

## 10. Open questions

1. Should the compliance harness live in the repo or beside the analytics toolkit in
   `~/.claude/scripts/relaticle-analytics/`? The repo makes it shared and reviewable; the toolkit
   keeps private-data tooling out of a public repository. Recommendation: the toolkit, with a
   pointer from the repo.
2. Do we keep `{{block:N}}` at all once placement is resolved server-side, or drop the marker
   from the model's surface entirely?
