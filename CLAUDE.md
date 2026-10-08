<laravel-boost-guidelines>
=== .ai/relaticle/architecture rules ===

# Architecture

Relaticle is a modular monolith: the CRM core lives in `app/`, and self-contained
subsystems live in `packages/<Name>/` with the `Relaticle\<Name>` namespace
(autoloaded from `packages/<Name>/src` via the root composer.json). Packages have
no composer.json of their own. Service providers are registered in
`bootstrap/providers.php`.

| Location | Owns |
|---|---|
| `app/` | CRM domain: models, actions, Filament app panel, API, MCP server |
| `packages/Chat` | AI assistant (agents, chat tools, credit system, streaming) |
| `packages/SystemAdmin` | Internal admin panel (separate Filament panel) |
| `packages/ImportWizard` | CSV import flows |
| `packages/Documentation` | Public docs pages |
| `packages/OnboardSeed` | Demo/onboarding data seeding |
| `packages/EmailIntegration` | Email + calendar sync (Gmail/Microsoft Graph), sharing, privacy |

Create a new package only for a genuinely separable subsystem with its own panel,
routes, or lifecycle. A new CRM entity is not one of those; it goes in `app/`. Package
anatomy mirrors a Laravel app: `src/`, `config/`, `routes/`, `resources/`,
`database/`.

## Module boundaries (enforced by tests/Arch/ArchTest.php)

- `App` must not depend on `Relaticle\SystemAdmin`; `Relaticle\SystemAdmin` may
  only reach back into `App\Models`, `App\Enums`, `App\Rules`
- `packages/EmailIntegration` owns its controllers, jobs, policies, views, config and
  timeline entries. `App\Http`, `App\Jobs`, `App\Policies`, `App\ActivityLog` and
  `App\Console` must not use it. The app reaches it only from CRM resources, models,
  onboarding, panel wiring and the MCP email tools in `app/Mcp/Tools/Email`, and
  anything it exposes to them checks the feature flag
- Never use the custom-fields package models directly. Use the `App\Models\CustomField*`
  subclasses (runtime model swapping is configured in `AppServiceProvider`)
- `packages/SystemAdmin` is excluded from PHPStan. When adding or removing enum
  cases, manually sweep SystemAdmin for `match` expressions over that enum (this
  exclusion already caused a production `UnhandledMatchError`)
- `app/Queries` holds the filter and sort language every list surface shares, laid out
  like spatie/laravel-query-builder's own `src/`. A class that implements Spatie's `Filter`
  lives in `app/Queries/Filters`, and a `Sort` in `app/Queries/Sorts`. Shared traits go in
  `app/Queries/Concerns`, and registries sit at the root. `app/Queries` never uses a
  transport: `App\Mcp`, `App\Http`, `App\Filament`, `App\Livewire`, `App\Scribe` or
  `Relaticle\Chat`. Enums stay in `app/Enums`, because the Pest Laravel preset fails one
  anywhere else. `.ai/rules/queries.md` holds the rules for extending it

## Actions (business operations)

An action is one business operation: a class that takes input, does something, and gives
output. Most are writes. Write operations (create, update, delete) that reach the domain from
a transport surface go through action classes in `app/Actions/<Domain>/`. Never inline business
logic in controllers, MCP tools, Livewire components, or Filament resources.
Actions are the single source of truth for business logic and side effects
(notifications, syncs, etc.).

A step or a rule that two actions share is an action too, and they inject it.
`PrepareAgentEmail` checks the capability, the mailbox and the recipient limit, and
`QueueAgentEmailAction` composes it. A reusable read that returns records is a query class,
never an action.

An action is named for what it does, verb first, with no `Action` suffix: `CreateOpportunity`,
`PrepareAgentEmail`. The `Actions` namespace already says what it is.
`tests/Arch/ConventionsTest.php` fails a class in an `Actions` folder whose name ends in
`Action`. The 53 EmailIntegration actions that predate the rule are listed in that test, and
the list only shrinks.

The canonical shape is `final readonly`, with a single `execute()` method and
authorization plus tenant-ownership checks inside the action itself.
`tests/Arch/ConventionsTest.php` fails an action in `app/Actions` or in a package's `Actions`
folder that exposes another public method. Five package actions predate the check. They are
listed in that test, and the list only shrinks:

```php
final readonly class CreateOpportunity
{
    public function execute(User $user, array $data, CreationSource $source = CreationSource::WEB): Opportunity
    {
        abort_unless($user->can('create', Opportunity::class), 403);

        TenantFkValidator::assertOwned($user, $data, [...]);
        // ...
    }
}
```

- Enforced by `EloquentWriteOutsideActionRule` (PHPStan): Eloquent writes in
  controllers, MCP tools, Livewire components, Filament classes, or chat tools
  fail analysis. Pre-existing violations are grandfathered per-file in
  `phpstan.neon`. When you refactor one, remove its ignore entry
- Filament CRUD may use native `CreateAction`/`EditAction` when the operation is a
  plain `Model::create()`/`->update()` with no extra logic. Side effects
  (e.g., notifications) must still be triggered via `->after()` hooks calling the
  appropriate action
- An action exists to keep business logic out of transport surfaces and to share one
  write between callers. A console command is neither: it has no `$user`, so the
  canonical `abort_unless` plus `assertOwned` shape does not apply. Give a command an
  action when a second caller shares the write, otherwise the logic lives in `handle()`
- When reviewing or refactoring code, extract inline business logic into action classes
- A structured payload is a class in a `Data` folder. Use spatie/laravel-data when untyped
  data becomes an object: a request, a stored JSON value, Livewire state
  (`Relaticle\ImportWizard\Data\ColumnData`). Use a plain `final readonly` class when code
  builds the object with `new` (`App\Data\ListQuery`). No test tells the two apart, so a
  reviewer reads for a data object that only ever meets `new`
- Name domain concepts plainly (`Plan`, not `AiPlan`). Context comes from the
  namespace
- `Support` holds code with no business rule in it: code that could ship as a standalone
  package, such as `App\Support\EmailAddress`. A class that knows a capability, a role, a
  sharing level or a workspace rule is an action, a query, a policy or a model method. No test
  reads for this, so a reviewer reads for a `Support` class that knows one

## Queries (the read path)

A reusable read is a query class, never an action. `tests/Arch/ConventionsTest.php` fails a
class under `Actions` named `List*`, `Find*`, `Search*`, `Get*` or `Aggregate*` ("keeps reads out
of the Actions folders").

| A read that is | Lives in |
|---|---|
| a predicate over one model's columns | a `#[Scope]` on the model |
| one entity's list, or a read across models | `app/Queries/<Domain>/<Name>Query.php` |
| a read over a package's own models | `packages/<Name>/src/Queries/<Name>Query.php` |
| a read with one caller | inline in that caller |

A query class is `final readonly`. The five list queries implement
`App\Queries\Contracts\EntityQuery` and use `App\Queries\Concerns\ListsEntity`. The shape is
an interface plus a trait, not a base class, because three arch tests forbid inheritance in `App`.
The trait authorizes and bounds the workspace itself, so a transport cannot forget either:

````php
final readonly class CompaniesQuery implements EntityQuery
{
    use ListsEntity;

    // fields() and includes()
}

$page = $query->paginate($user, $request->toListQuery());
````

- Each transport maps its own input to `App\Data\ListQuery`. A query class reads no ambient
  user, request or workspace, because chat tools run in queued jobs. `tests/Arch/ArchTest.php`
  fails it ("takes the acting user and reads no ambient user, request or workspace")
- `CrmEntity::query()` owns which query lists which entity. A list query declares `fields()` and
  `includes()`, and the trait derives the rest
- `paginate()` is the one entry. The builder behind it stays private until a caller needs more
  than a page, and `.ai/rules/queries.md` says how to open it
- A query class never writes. `EloquentWriteOutsideActionRule` (PHPStan) covers every `Queries`
  folder, and `tests/Arch/ConventionsTest.php` fails one that `phpstan.neon` does not list
- `.ai/rules/queries.md` holds the filter grammar and the rest of the rules

## One fact, one owner

A fact more than one surface publishes gets an owner class, and every surface reads it.
The working examples: `CustomFieldFilterSchema` owns filter operators,
`EntityFilters::definitions()` owns the filter names each entity accepts,
`App\Mcp\Schema\CustomFieldSchema` plus `CustomFieldType::inputFormat()` own how a
custom field is described to an agent, `CrmEntity::titleColumn()` owns the name column.
Facts that are a pure function of an enum case (a label, a format, a capability) live on
the enum, never in a private `match` inside a consumer.

The measurement that produced this rule: of four cross-surface axes, the two with an
owner class had zero drift and the two without had three, including a company owner the
REST API silently dropped while MCP and chat both wrote it.

`tests/Feature/CRM/SurfaceParityTest.php` is the gate for the surfaces that still carry
a per-entity copy: API form request, MCP tool, chat tool, MCP schema resource. Adding a
writable field or a relation include to one of them fails that test until the others
follow.

A query predicate over one model's columns has one owner too: a `#[Scope]` on that model,
such as `AgentConversation::ownedBy()` or `AgentConversationMessage::typed()`. Every
reader goes through it. A `DB::table` reader that wants plain rows keeps them with
`Model::query()->ownedBy($user)->toBase()` and never copies the `where` clauses. Readers
that each wrote their own filter drifted: `sentBy()` skipped the typed check and tagged
users `has-ai-usage` who had never typed. Scoped queries take no table alias, because
`whereKey()` and `whereRelation()` qualify columns with the table name, which Postgres
rejects under an alias. `tests/Arch/ConventionsTest.php` fails when a public method
outside a model, enum, or `Scope` class takes a query builder.

## Business language

One word per business concept. The model class owns the word, and code, tests and copy use it.

| Concept | Word | Retired |
|---|---|---|
| The tenant | workspace (`App\Models\Workspace`) | team |
| A user's place in a workspace | member (`App\Models\Membership`, `WorkspaceRole`) | the `editor` role key |
| A business the workspace tracks | company (`App\Models\Company`) | account as the record's name |
| A human the workspace tracks | person, people (`App\Models\People`) | contact as the record's name |
| A sale in progress | opportunity (`App\Models\Opportunity`) | deal as a label or as the record's name |

`team` survives in three places. Jetstream's own contract and event names keep it
(`AddsTeamMembers`, `TeamMemberAdded`). `teammate` is a person, not the tenant. A company's
team in the email composer (`company_team`) means the people at a CRM company. `tenant` is
the custom-fields package's word and stays at that boundary: `TenantContextService`, and the
`team` relation name the package registers. Stripe objects already carry a `team_id` metadata
key, so billing still writes and reads it.

The model is `People` and the singular is person. `contact` survives where it names something
else. The opportunity's `contact` relation carries the "Point of Contact" label. "Contact us"
and the contact form are a verb and a page. On the email privacy page, contacts are addresses
and domains. `deal` stays the plain word in prose ("track a deal through the pipeline") and
never labels the record. Tool descriptions and schema resources keep both retired words as
synonyms, so a model maps the user's word to the right tool. What the assistant itself says is
rule 11 of the `CrmAssistant` prompt: the app's names, or the word the user chose.
`tests/Feature/Chat/CrmAssistantInstructionsTest.php` fails when that rule changes.
`NextStepSuggester` writes the chips above the message box and copies the reply's nouns, so
its prompt carries the same rule. `tests/Feature/Chat/NextStepSuggestionTest.php` pins it.

`tests/Arch/ConventionsTest.php` fails an identifier that uses `team` for the tenant, and any
use of the retired `editor` role key. It fails a bare `Contact` or `Deal` label anywhere in
source. It fails `contacts` and `a contact` in published copy: `lang/`, `resources/views/`,
`resources/js/` and each package's `resources/`. The gate reads no other form of the two
words, so a reviewer does.

`account` names the sign-in and a connected mailbox, never a company. The company's
"Account Owner" field keeps the word, and so does its `account_owner_id` column. No test can
tell these senses apart, so a reviewer reads for a company called an account.

## i18n enforcement

Two custom PHPStan rules (`app/PHPStan/Rules/`) forbid hardcoded user-facing
strings: `HardcodedUserFacingStringRule` (guarded methods like `label()`,
`heading()`, `title()`) and `HardcodedStaticPropertyRule` (guarded static
properties like `$navigationLabel`). Wrap user-facing strings in `__()`.
Some paths are deferred via explicit ignores in `phpstan.neon`. Don't add new
ignores without approval.

=== .ai/relaticle/chat rules ===

# Chat

## Verifying chat changes

Chat features MUST be verified against the production-shaped stack before being
reported done: Horizon running, `QUEUE_CONNECTION=redis` (not sync), Reverb up,
and the full loop walked in a real browser (send → stream → proposal card →
approve/reject). Sync-queue testing masks exactly the bug class that reaches
production: message ordering, approval races, duplicate proposals.

- When a production chat transcript is given as a bug report, enumerate every
  defective turn as a separate defect (ordering, duplicate/stale proposal cards,
  rate-limit UX, wrong success messages), reproduce each locally, and track them
  as a checklist. Never fix only the most visible one.
- When one chat tool has a bug, sweep its sibling Create/Update/Delete tools for
  the same class of bug before closing.

## Tool design

- Prefer giving the agent a tool (e.g. `ListWorkspaceMembersTool`) over injecting
  tenant data into the system prompt. Add prompt-context injection only when a
  tool round-trip is demonstrably too costly.
- Every write tool takes batch input: `records[]` on create and update,
  `ids[]` on delete. One call → one `PendingAction`; a multi-record proposal is
  a `_batch` the dock resolves per item. Do not add scalar-only tools.
- A request needing several writes is ONE turn: the assistant chains the write
  tools and links them with `$ref:<pending_action_id>` where a record it just
  proposed would go. Proposals sharing a `turn_id` are one plan, presented as a
  single card and approved once (`ProposalPlanService`). A new foreign key on a
  write tool must be listed in `ownedForeignKeys()`/`ownedForeignKeyLists()`, or
  it will accept neither reference validation nor ownership checks.
- A write that leaves the workspace is approved on its own: an email send and a
  workspace invitation. `ProposalEntity::needsOwnApproval()` owns that list.
  `ProposalPlanService::approveAll()` skips such a step: it stays pending, shown in
  full, with its own button, and the keyboard shortcut never approves it.
  `SendEmailTool` takes one email per call, and `PendingAction::isEmailSend()` makes
  `approveStep()` refuse a batch of them. An invitation step may hold several
  addresses, and its button sends them all. `tests/Feature/Chat/EmailToolsTest.php`
  and `tests/Feature/Chat/InviteWorkspaceMemberToolTest.php` fail when "Approve all"
  or its shortcut sends either.
- `Relaticle\Chat\Enums\ProposalEntity` owns every fact that follows from what a proposal
  writes: its title key, its core fields, its verbs, and whether it is approved on its own.
  `PendingAction::entity_type` is cast to it. A new kind of proposal is a new case, and every
  `match` in the enum lists each case with no `default`, so PHPStan fails a case that a
  fact forgot. Strings remain only where another vocabulary starts: the custom-fields
  bridge, `RecordReferenceResolver`, and payloads sent to the browser or the model.
- Resolve a reference only at approval time (`PlanReferenceResolver`), never at
  proposal time, and never let a `$ref` fall out of a card's display: a plan card
  that hides the link being approved is the failure this design exists to
  prevent (`RecordNameResolver` renders it as "Name (step N)").
- Read tools take `lookup: true` to skip the `display_block`; the prompt tells
  the model to use it (or `SearchCrmTool`) when it only needs ids. Every read
  result without that flag renders, so a new read tool must either emit a block
  or be named in the prompt's no-block list.
- A new list tool needs `availableIncludes()` (copy its sibling `Get*Tool`'s
  allowlist) or the prompt's related-records rule has nothing to call for it.
- Replayed proposal tool results are NEVER rewritten: mutating an earlier
  message invalidates the Anthropic prompt-cache prefix from that turn on.
  Decided status travels in `<resolved_actions>`, re-queried per turn, and in the
  resume opener row the decision appends (`ResolvedActionText` owns both texts);
  auto-cancelled status in `<superseded_proposals>`. Never label a proposal by
  its card heading.
- A field reachable in the Filament form must be settable from chat; the
  assistant answering "that field isn't supported" is a bug, not a limitation
  to document.
- Every tool registered on `CrmAssistant` needs a label in the `toolLabels` map
  (`packages/Chat/resources/views/livewire/chat/chat-interface.blade.php`), or the
  streaming shimmer falls back to "Running <tool name>…" and leaks the identifier.
  `tests/Browser/Chat/LoadingShimmerTest.php` is the gate. Run that one file after
  registering a tool: `php artisan test tests/Browser/Chat/LoadingShimmerTest.php`.
- A tool whose action works on the workspace rather than on records (`RemoveSampleDataTool`
  is the precedent) does not fit the per-record proposal pipeline: `executeDelete()` resolves
  models from `_record_ids`/`_model_class`. Such a tool carries neither marker, is branched
  explicitly in `PendingActionService::executeDelete()`, and its action must be listed in
  `ALLOWED_ACTION_CLASSES`. Re-check the actor's authority inside the action: `ProposalOwnership`
  only proves the proposal belongs to the approver's workspace, not that they may run it.
- Deleting a record never cascades to the records linked to it. An assistant that says it
  does is wrong, and a removal that must span entities needs its own tool rather than one
  entity's delete standing in for the rest.

## Chat tools + custom fields

Chat tools (`packages/Chat/src/Tools/*/Create*Tool.php` and `Update*Tool.php`) automatically support **every** active custom field for their entity. Adding a new field to `app/Enums/CustomFields/*Field.php` (or via the Custom Fields admin UI) is enough. Do NOT add per-field schema slots, value coercion, or display rows to the chat tool. The bridge services in `packages/Chat/src/Services/Tools/` handle:

- Inlining a per-tenant `custom_fields` schema description so the LLM knows the valid codes and option labels.
- Translating option labels back to option IDs at validation time.
- Formatting the proposal-card "old → new" diff per field type.

If you need a custom field to be **un-settable** from chat, mark it `active=false` on the `custom_fields` row, or add a tool-side allowlist filter inside `CustomFieldsSchemaDescriber`. Don't reach for hand-rolled per-field code.

=== .ai/relaticle/core rules ===

# Project

This is production code for a commercial SaaS product with paying customers.
Bugs directly impact revenue and user trust.

Treat every change like it's going through senior code review:

- No lazy shortcuts or placeholder code
- Handle errors and edge cases properly
- Write code that won't embarrass you in 6 months

A rule that cannot name the artifact failing when you break it is decoration. Every
rule here names a class, a test, or a command, because the one abstract rule this file
used to carry ("never store the same fact in two places") was in force for the three
months two copies of the same field vocabulary drifted apart.

## Database

- This project uses **PostgreSQL exclusively**. Do not add SQLite/MySQL compatibility layers, driver checks, or conditional SQL
- Migrations must only have `up()` methods. Never write a `down()` method
- Prove a migration by rehearsing it on anonymized production data, never with a test. A test
  seeds the rows its author imagined. Production holds the rest: `creation_source = 'system'`
  meant both seeded samples and mailbox-synced contacts, which no fixture mixed. The rehearsal:
  1. Export read-only from production: `pg_dump -s` for the schema, `pg_dump -a -t migrations`
     so only the new migration is pending, and `\copy` of every table the migration reads.
     Replace personal columns in the export query (names, emails, bodies, free-text JSON values)
  2. Load the export into a scratch database. Tables loaded without their parents need
     `set session_replication_role = replica`. A migration that inserts needs a stub parent row
     for every enforced foreign key, because `migrate` runs with the checks on. Save a
     before-state query of the rows in scope
  3. Run `DB_DATABASE=<scratch> php artisan migrate --force` and confirm only the new migration
     ran. Diff the after-state against the before-state, row counts and the rows it must leave alone
  4. Run `migrate` again to prove it is a no-op, then drop the scratch database and delete the export
- A data backfill the query builder can express belongs in the migration, chunked with
  `eachById`: no models, no file access, no app code. This is the only shape that reaches a
  self-hosted install unaided. `2026_09_10_000000_convert_markdown_editor_custom_fields_to_rich_editor`
  is the worked example, and Spatie ships the same shape in `laravel-activitylog` UPGRADING.md
- A backfill that needs models, files, or another service is a command instead: it reports by
  default, writes only on `--force`, and re-runs after a partial failure. A migration can do
  none of that, because we never write `down()`
- A self-hosted upgrade is `docker compose pull && up -d`: migrations run, nothing else, so no
  command of ours ever runs there. Ship a change of storage shape behind a read-path shim that
  keeps the old shape working (`RichContentAttachments::getFileAttachmentUrl()` still serves a
  legacy bare-filename `data-id`), then queue the command from a migration, as
  `2026_09_15_150837_queue_rich_editor_attachment_backfill` does:
  `Artisan::queue($command, ['--force' => true])->onQueue('imports')->delay(now()->addMinutes(5))->afterCommit()`.
  Pgsql wraps every migration in a transaction, so without `afterCommit` a worker can start before
  the DDL lands. The delay holds the job until the deploy has restarted Horizon: a worker booted
  before the deploy does not know a new command, and on 2026-09-24 one failed
  `media:purge-unsafe-images` on both tries, so the purge never ran in production. `imports` is the long lane (300s, 2 tries, 256MB) where `default` allows 60s and one
  try, and `QUEUE_CONNECTION=sync` runs the command inline, so it stays chunked and idempotent
  either way. Never `Artisan::call()` in `up()`: the container entrypoint runs under `set -e`, so
  a throw there crash-loops the app and takes Horizon down with it. `tests/Arch/ConventionsTest.php`
  fails when a migration names a command that no longer exists, or queues one without a delay
- A migration that drops a column queued jobs still read or write ships with a deploy step:
  `php artisan horizon:pause` before `migrate`, `php artisan horizon:terminate` after. Otherwise a
  job still running the old code hits the missing column and fails with no retry.
  `2026_09_24_100100_backfill_agent_conversation_message_steps` is the precedent. A self-hosted
  upgrade needs nothing extra, because `up -d` stops the old container before migrating
- Every datetime column is `timestamp without time zone` holding **UTC**. Never write one from
  the database clock. That rules out `DB::raw('now()')`, `CURRENT_TIMESTAMP`, and `->useCurrent()` /
  `->useCurrentOnUpdate()` column defaults. Those resolve against the *session* timezone and
  write local wall-clock into a UTC column. Pass a PHP-side `now()` instead:
  `->update(['used_at' => now()])`. The pgsql connection pins `'timezone' => 'UTC'` so the two
  agree today. Do not rely on that. It is the safety net, not the contract.

## Dates

- Dates are immutable application-wide. `AppServiceProvider::register()` calls
  `Date::use(CarbonImmutable::class)`, so `now()`, `today()`, the `Date` facade, and
  every `datetime` cast return `CarbonImmutable`
- Never name the mutable `Carbon` class in code. `CarbonImmutable` does not extend it,
  so a type hint becomes a TypeError and an `instanceof` check silently turns false.
  `tests/Arch/ConventionsTest.php` fails on a bare `Carbon` anywhere in `app/`,
  `packages/`, `database/`, or `tests/`
- Type a date as `CarbonImmutable` when our own `Date::` factory or a model cast
  produced it. Use `CarbonInterface` when a vendor may still hand you a mutable date
- Build dates through `now()`, `today()`, or the `Date` facade. A hardcoded `Carbon::`
  static call bypasses the factory, and `CarbonToDateFacadeRector` rewrites it
- Steer the clock in tests with `$this->travelTo()`. `Carbon::setTestNow()` names the
  mutable class, so `CarbonSetTestNowToTravelToRector` rewrites it

## Quality Checks

The local loop is scoped to the change. GitHub CI (`.github/workflows/ci.yml`) is the
only full run: it executes lint, rector, PHPStan, type coverage, five test shards and
six Browser shards on every push to a pull request. A run takes about 4 minutes when
runners are free, and longer when several runs queue for them.

After each change, while iterating:

1. `vendor/bin/pint --dirty --format agent`: fix code style
2. `php artisan test --compact <paths>`: the test files you touched, plus the tests
   that exercise the classes you changed (`grep -rl 'ClassName' tests`)

Once, before pushing:

3. `vendor/bin/rector --dry-run`: if rector suggests changes, apply them with `vendor/bin/rector`
4. `vendor/bin/phpstan analyse`: ensure no new static analysis errors
5. `composer test:lint`: `--dirty` only covers uncommitted files, so a file committed
   earlier in the branch is checked here (`pint --test --parallel`, whole repo)
6. `composer test:arch`: `ArchTest` and `ConventionsTest` fail CI on more branches than
   any other test class, and the Arch suite runs in 30 seconds

After a push, open the pull request if the branch has none, and watch the `Tests`
workflow as a background task:
`gh run watch --exit-status $(gh run list --branch <branch> --workflow Tests --limit 1
--json databaseId --jq '.[0].databaseId')`. Never a `sleep` loop. Fix what it reports
and push again.

Never run `composer test:pest`, `composer test:pest:full`, `composer test:type-coverage`
or `composer test:browser` locally to confirm a commit or a push. CI runs all four on the
pushed commit, and a local run slows every other workspace on the machine: the full suite
takes 116s alone and 514s beside three other heavy jobs. Run one locally only to reproduce
a CI failure, scoped to the failing file.

Do not add new PHPStan ignores without approval. All parameters and return types must be explicitly typed. Untyped closures and parameters fail type coverage in CI.

## Fixing & Verification

- Never change production code solely to make a test or CI pass. A failing check
  means one of: production bug, wrong assertion, or test-state leak. Diagnose
  which first, then fix at that layer. A production behavior change must be
  justified on its own merits and covered by its own dedicated test.
- After any fix, re-run the original failing repro (test, browser flow, query)
  and show the new output before claiming it is fixed. "Should work now" is not done.
- Before reporting an investigation or cleanup complete, do a second independent
  verification pass: re-grep all references, re-run the checks, re-walk the repro.
- Debug production errors by reproducing them locally first (failing test, seeded
  data, or browser repro with the real queue). Production access (Tinkerwell/SSH)
  is for short read-only queries that capture the failing payload or state.
  It is never the iteration loop.
- When a failing operation has a working sibling (approve vs reject, one entity
  type vs another), diff the two code paths first. It is the fastest localizer.

## Minimal Change

- Default to the smallest change that satisfies the requirement. Every new file,
  script, DB column, or abstraction must be justified by an explicit need. When
  in doubt, leave it out and propose it instead.
- Internal contracts (chat tool schemas, action signatures, internal APIs) have
  no external consumers. When extending one, migrate all callers in the same
  change. Never leave deprecated parameters, fallbacks, or dual old/new paths.
- Environment-specific developer data belongs in `database/seeders/LocalSeeder.php`.
  Never put it behind an `app()->environment()` branch inside `app/Actions/` or
  other production code.

## Code shape

The reference is the framework's own code and Spatie's packages. Measured on the installed
versions, nine of their methods in ten are under about 20 lines, and ninety-nine in a hundred
are under about 50. Each rule below names what fails when it is broken.

- A method stays within 60 lines. `MethodLengthRule` (PHPStan, `app/PHPStan/Rules/`) fails a
  longer one. Extract a step and name it for what it returns. `packages/SystemAdmin` is outside
  PHPStan, so the cap does not reach it.
- The methods that were already longer are listed in `phpstan-method-length.php`, and that list
  only shrinks. A listed method that grows fails. One that shrinks has its entry lowered, and one
  that fits has it removed. `tests/Arch/ConventionsTest.php` fails an entry whose method is gone.
- A class is named for its role where its directory carries one: `Command`, `Controller`,
  `Request`, `Resource`, `Mail`, `Observer`, `Policy`, `Tool`, and `Filter` and `Sort` under
  `app/Queries`. `tests/Arch/ConventionsTest.php` fails a class there without the suffix.
- Code reaches the network, the shell, and a wait through `Http`, `Process`, and `Sleep`. A test
  can fake each of them, and nothing can fake the raw call. `tests/Arch/ArchTest.php` fails a
  direct Guzzle client, `curl_*`, Symfony `Process` or `HttpClient`, `sleep()`, and `usleep()`.
- Before writing a helper, look for it in PHP, then in the framework, then in a package from
  `composer.json`, then in this codebase. Rector's Laravel sets rewrite the hand-rolled forms
  they know, and `composer test:refactor` fails until the rewrite is taken.
- A model or a job states its configuration as PHP attributes: `#[Fillable]`, `#[Table]`,
  `#[Unguarded]`, `#[Tries]`, `#[Timeout]`, `#[Backoff]`, `#[UniqueFor]`. Rector rewrites the
  property form, and `composer test:refactor` fails until the rewrite is taken. Code that needs
  the number at runtime reads a class constant the attribute also uses, as `SendEmailJob` does
  with `TIMEOUT_SECONDS`. `$this->timeout` no longer exists once the attribute replaces it.
- An accessor is `Attribute::get()` with a typed closure and a `@return Attribute<TGet, never>`
  docblock. Larastan reads the property type from that docblock, so PHPStan reports the
  attribute as an undefined property without it.

## Comments

Write code that needs no comment. In a finished diff, 90%+ of the code carries zero
comments: names, small methods, and a test named for the behaviour say it all. A comment
is the exception that admits the code could not.

- A comment states only what code cannot: a non-obvious *why*, a magic value's source, or
  a warning against a refactor that looks safe. Never what the code does.
- Cap it at 2 lines. Longer rationale belongs in the PR body or the commit, not the file.
- Never narrate the diff (`// added to fix X`), argue it (*without this*, *otherwise*,
  *this ensures*), or carry traceability (ticket IDs, criterion tags). The reviewer reads
  the PR; the next reader reads the code.
- No comments in tests. The test name carries the intent.
- Docblocks carry types, generics, and array shapes PHPStan cannot infer. Never prose.
- Draft with comments if it helps you think. Before handing over the diff, re-read every
  `//` you added and delete any the code already says.

## Scheduling

- All scheduled commands go in `bootstrap/app.php` via `withSchedule()`, not in `routes/console.php`

=== .ai/relaticle/custom-fields rules ===

# Custom Fields

- Models using the `UsesCustomFields` trait handle `custom_fields` automatically. Do NOT manually extract, strip, or call `saveCustomFields()` in actions
- The trait merges `'custom_fields'` into `$fillable`, intercepts it during `saving`, and persists values during `saved`. Just pass `custom_fields` through in the `$data` array to `create()`/`update()`
- Tenant context for the custom-fields package is set in `SetApiWorkspaceContext` middleware via `TenantContextService::setTenantId()`. Actions don't need `withTenant()` wrappers
- In Filament, the package's own `SetTenantContextMiddleware` handles tenant context. No action-level code is needed there either
- `CustomFieldValidationService` intentionally uses explicit `where('tenant_id', ...)` with `withoutGlobalScopes()`. This is defensive and correct; don't change it to rely on ambient state
- Every write path that is NOT a Filament panel request or behind `SetApiWorkspaceContext` (chat action approval, queued jobs, webhooks, commands) must set `TenantContextService::setTenantId()` before saving custom fields. Otherwise `saveCustomFields` iterates every tenant (gateway timeouts + cross-tenant writes). Wrap manual calls in try/finally restoring the previous tenant id (mirror `SetApiWorkspaceContext`)
- Writing null/empty for a custom field is how a value is cleared. Never skip or filter out "empty" values on save; only keys absent from the payload are left untouched. Verify any persistence change in both directions (set a value AND clear it), through both the panel form and the chat/API path
- Retire a field type through `config('custom-fields.field_type_configuration')->disabled()` and keep its `CustomFieldType` case. Stored rows still need a type name and a write format when a schema lists them, and `CustomFieldType::from()` throws without the case. `FILE_UPLOAD` is the precedent
- Sections, validation rules, conditional visibility and field width are package features Relaticle does not use. `config/custom-fields.php` disables all four, so the settings form never shows them. They are never a parity gap: do not add them to a chat tool, an MCP tool or the API
- Deleting a field definition is permanent: the package observer deletes its options and every stored value with it. `CustomFieldDefinitionValidator::forDelete()` owns the gate the settings form applies: never a system-defined field, and an active field only when no record holds a value. `DeleteCustomField` re-checks it inside the approving transaction

=== .ai/relaticle/testing rules ===

# Testing

The suite follows the Testing Trophy. Every test file must live inside one of
these directories. They are the phpunit testsuites, and
`tests/Arch/TestSuiteIntegrityTest.php` fails if a `*Test.php` exists anywhere
else (files outside a declared suite silently never run):

| Layer | Directory | Scope |
|---|---|---|
| Architecture | `tests/Arch/` | structural rules, module boundaries |
| PHPStan rules | `tests/PHPStan/` | tests for the custom static-analysis rules |
| Smoke | `tests/Smoke/` | HTTP-level route smoke |
| Workflow | `tests/Feature/` | the bulk of the suite, through real entry points |
| Browser | `tests/Browser/` | critical paths only |

There is deliberately **no `tests/Unit/` suite**. Do not create new top-level
test directories; if one is ever needed, declare it in BOTH `phpunit.xml` and
`phpunit.ci.xml`. `TestSuiteIntegrityTest` enforces that the two stay in sync.

## Rules

- Do not write isolated unit tests for action classes, services, enums, or other
  internal code. Test them through their real entry points (API endpoints,
  Filament resources, Livewire components). Isolated unit tests of internals
  create maintenance burden without catching real bugs.
- Never weaken an assertion, delete a test, or special-case production code just
  to turn the suite green. If a test asserts a stale value, fix the assertion;
  if state leaks between tests, fix isolation in the test layer. Never push
  compensation into production code.
- Never write tests that assert on source code as text (reading a Blade/PHP file
  and checking it contains a string). They break on refactors and pass on broken
  behavior. Test the rendered/runtime behavior instead.
- Do not write tests for migrations, schema changes and backfills included. Rehearse them on
  anonymized production data instead, as the Database section of `core.md` describes.
  `tests/Arch/ConventionsTest.php` fails when a test outside `tests/Arch/` loads a migration file.
- `tests/Pest.php` binds `TestCase` + `LazilyRefreshDatabase` for the Feature,
  Smoke, and Browser suites. Don't repeat `uses(...)` per file there.
- Use `mutates(ClassName::class)` in test files to declare which source classes
  each test covers
- Run mutation testing per-class as a code-review tool (no CI gate):
  `php -d xdebug.mode=coverage vendor/bin/pest --mutate --class='App\MyClass' tests/path/`
- Use `$this->travelTo()` in tests that depend on day-of-week or weekly intervals
  to avoid flaky boundary failures
- Match test organization to existing conventions: before creating a test file,
  search `tests/` for files covering the same class or feature and extend those
- A negated arch expectation covers one layer: `expect($layer)->not->toUse(...)` inside a
  `foreach`. Pest fails `expect([$a, $b])->not->toUse(...)` only when every layer violates
  at once. Two module-boundary checks passed that way with 20 violations behind them.
  `tests/Arch/ConventionsTest.php` fails the multi-layer form. Plant a violation to prove a
  new arch check before trusting it

## Running the suite

- The normal local run is scoped: `php artisan test --compact <paths>` over the test
  files you touched and the tests that exercise the classes you changed. The Quality
  Checks section of `core.md` lists the whole loop.
- The merge gate is the `Tests` workflow on GitHub, which runs the complete suite on
  every push to a pull request. After a push, watch it as a background task. Do not run
  the complete suite locally to confirm a push.
- `composer test:pest` (parallel, TIA enabled, excludes Browser) and
  `composer test:pest:full` (non-TIA) stay available for reproducing a CI failure that
  a scoped run cannot, and for recording a TIA graph. TIA replays a cached pass whenever
  a test's edges are unchanged, so it cannot see time-dependent failures (`travelTo`,
  expiring tokens), `.env` edits, or dynamic dispatch it did not trace while recording.
- After changing test timings materially, refresh the CI shard balance with
  `composer test:update-shards` and commit `tests/.pest/shards.json`; a stale
  file silently drops new test classes out of time-balancing.

=== .ai/relaticle/ui rules ===

# UI

## Verifying visual work

- Verify every visual change with an agent-browser screenshot (light + dark, and
  mobile viewport where relevant) before reporting it done. Never make the user
  act as the renderer.
- Any change to a Blade view, Livewire component, or Filament page must be
  clicked through with agent-browser (including empty-state data) before being
  reported done. Tests passing is not sufficient for UI work.
- agent-browser and the Browser suite both drive Chromium, and CI installs
  Chromium only (`.github/workflows/ci.yml`). A defect that only WebKit shows
  passes every gate. After the Chromium pass, run the browser tests for the
  surfaces you changed in WebKit:
  `php artisan test --compact <files> --browser safari`. Install the engine once
  per machine with `pnpm exec playwright install webkit`.
- An SVG that reaches the page as a string (`svg()->toHtml()` inside `@js`, a
  JavaScript template) carries its own size class:
  `svg('ri-claude-fill', 'size-full')`. With only a `viewBox`, Chromium stretches
  it to its flex slot and WebKit collapses it to 0x0.
  `tests/Browser/Chat/ModelPickerTest.php` fails under `--browser safari` when a
  model picker icon loses its size.
- A visual bug the reporter sees and agent-browser does not show is an engine
  difference until proven otherwise. Reproduce it in WebKit before changing
  code, then re-run that repro after the fix.

## Marketing & demo surfaces

- Mockups of the product (hero tabs, demos) mirror the real app UI 1:1.
  Screenshot the actual app first and match sidebar, spacing, and component
  placement. External sites (e.g. attio.com) are inspiration for concept only,
  never for visual specifics.
- Use design tokens from `resources/css/theme.css`; don't introduce ad-hoc pixel
  values or colors without a semantic token.
- Demo/example content (names, companies, conversations) must read like real CRM
  data for the buyer persona. No placeholder-looking values.

## Icons (Remix Icon)

- **Brand/social icons** (GitHub, Discord, Twitter, LinkedIn) → always `fill` variant
- **UI/functional icons** (arrows, chevrons, checks, close) → always `line` variant
- **Feature/section icons** → `line` variant, stay consistent within a section
- **Status/emphasis icons** (success checkmarks, alerts) → `fill` variant

=== .ai/relaticle/workflow rules ===

# Workflow

## Decisions

- For design decisions, present numbered/lettered options with a comparison
  matrix and one clear recommendation. Batch independent questions so they can
  all be answered in a single message; expect one-character answers.

## Guidelines pipeline

- `CLAUDE.md`, `AGENTS.md`, and `GEMINI.md` are compiled artifacts. Edit the
  sources in `.ai/guidelines/relaticle/`, then run `php artisan boost:update`
  and copy `AGENTS.md` to `GEMINI.md` (boost does not write it). Never edit the
  compiled files directly; `tests/Arch/ConventionsTest.php` fails when they drift.
- A bundled Boost line that contradicts a rule here is deleted, never argued with.
  Copy the bundled file to the same path under `.ai/guidelines/` and delete only that
  line: `.ai/guidelines/php/core.blade.php` replaces Boost's `php/core`.
  `tests/Arch/ConventionsTest.php` pins each deleted line and fails when Boost changes
  the file, so the copy is refreshed instead of going stale. An override never adds
  text. Project rules go in `.ai/guidelines/relaticle/`.

## Releases

- Merge to main and tag only on explicit instruction, never on your own.
- Procedure: merge → `git checkout main && git pull` → confirm local and remote
  parity (`git log origin/main..main` and the reverse are both empty) → tag
  `vX.Y.Z` (minor for features, patch for fixes) → `git push origin <tag>`.

## Issues and milestones

- Milestones are **product themes, never releases**, and never carry a version
  number. Releases are cut from merged PRs and land continuously, so a milestone
  can never track a version: naming them `vX.Y` guaranteed drift, and it did.
  `v3.5.0` shipped the chat rebuild and MCP work while the `v3.5` milestone held
  one unstarted issue that did not ship.
- The live themes are `Infrastructure & Data`, `Integration Platform`,
  `User Experience`, `Billing & Monetization`, and `AI Intelligence`. Each carries
  its scope in its GitHub description. Put a new issue in the theme it belongs to;
  do not re-sync milestones with release tags.
- Milestones carry no due dates. A theme is not time-boxed, and a permanently
  overdue date trains everyone to ignore the field.
- Every issue gets a milestone, an issue type (Bug, Feature, or Task), and a
  Roadmap project status (default Todo). Ask which milestone before choosing one.
  Issue type is set with the GraphQL `updateIssue` mutation and `issueTypeId`;
  `gh issue edit` does not support it.

## External communication

- PR/issue comments, Discord replies, and any other outbound text: show the
  draft and wait for an explicit "post" before publishing.
- Never claim a product capability (in PR bodies, replies, docs) without
  verifying it works in the current codebase. Feature claims must be backed by
  code or a browser repro.

=== .ai/relaticle/writing rules ===

# Writing

Everything this repo publishes is product surface: marketing pages, help and
docs content, blog posts, UI strings, lang files, commit subjects, and PR
bodies. Buyers read it. Search engines and AI assistants index it.

## No em-dashes

Never use an em-dash (U+2014) in copy, docs, comments, commits, or PRs.

The glyph is half the problem. The tell is the cadence it carries: a short
clause, the dash, then an appositive restating the clause.

    Bad:  Export anytime — your data is yours.
    Bad:  Export anytime, your data is yours.
    Good: Export anytime. Your data is yours.

Swapping the dash for a comma keeps the cadence and fixes nothing. Rewrite the
sentence. Two sentences usually, or a colon when the second half genuinely
explains the first. Never run a find-and-replace over the character.

Vary construction. A writer reaches for one rhythm now and then. A page that
reaches for it fifteen times reads as one template applied over and over,
whatever punctuation it wears.

`tests/Arch/ConventionsTest.php` enforces this across `app/`, `packages/`,
`resources/`, `lang/`, `config/`, `database/`, `routes/`, and `bootstrap/`. One
exception is allowlisted: the standalone `'—'` string literal, used as a data
glyph for empty values in activity-log and custom-field diffs. Never as prose
punctuation.

One trap when rewriting: a colon is the natural replacement, but a bare `: `
inside an unquoted YAML front-matter value in
`packages/Documentation/resources/content` throws a ParseException that 500s
every help and docs page, not just that file. Use a period or a comma there.

## Record names

Copy calls a record what the product calls it: company, person, opportunity, task, note.
The glossary and the words it retired are in `architecture.md`, under Business language.

"People" is also the plain word for humans. A possessive in front of it reads as the
reader's own staff.

    Bad:  Relaticle brings your people, companies and sales pipeline together.
    Good: Relaticle keeps the people and companies you sell to in one CRM.

Say whose they are after the noun, or use the singular: "every company, person, and
opportunity". `tests/Arch/ConventionsTest.php` fails `your people` in published copy.

Give "people" one meaning per sentence. When the record and the humans who use the
product meet, the humans are "you" or "your team". No test reads for this.

## The assistant and the MCP server

The built-in assistant has a name, `config('chat.assistant_name')`. The first mention on a
page is "Rela, the built-in AI assistant". After that it is "Rela". "AI chat" is not a name
for it. External agents reach Relaticle through "the MCP server", and copy names Claude and
ChatGPT where there is room, because a buyer knows those and may not know MCP.

Pitch copy states no tool count and no field type count. It says what an agent or a team
can do. The MCP guide's tool reference is the one place that counts tools.

    Bad:  Explore the AI assistant and 39 MCP tools
    Good: Explore Rela and the MCP server for Claude and ChatGPT

`tests/Arch/ConventionsTest.php` fails "AI chat" in published copy, and fails the MCP guide
when its count differs from the tools `RelaticleServer` registers.
`tests/Feature/Public/PublicPagesTest.php` fails a marketing page that quotes a count.

## House style

- One idea per sentence. 25 words maximum. Active voice.
- Same term for the same thing every time. Lead with the answer.
- Cut every word that does no work. Write person to person, not corporate.
- No emojis in product copy, docs, or commits.

=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `pnpm run build` or ask the user to run `pnpm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Follow existing application Enum naming conventions.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== spatie/laravel-activitylog/core rules ===

# spatie/laravel-activitylog

Activity logging package for Laravel. Logs model events and manual activities to a database table.

## Key Concepts

- **Activity**: An Eloquent model (`Spatie\Activitylog\Models\Activity`) storing log entries with subject, causer, event, attribute_changes, and properties.
- **Subject**: The model being acted upon (polymorphic `subject_type`/`subject_id`).
- **Causer**: The model that caused the action, typically the authenticated user (polymorphic `causer_type`/`causer_id`).
- **LogOptions**: Fluent configuration object returned by `getActivitylogOptions()` on models using the `LogsActivity` trait.
- **ActivityEvent**: Enum with cases `Created`, `Updated`, `Deleted`, `Restored`.
- **`attribute_changes`** column: stores `{"attributes": {...}, "old": {...}}` for tracked model changes.
- **`properties`** column: stores custom user data set via `withProperties()`.

## Traits

### `LogsActivity`

Add to models to automatically log create/update/delete events. Optionally implement `getActivitylogOptions()` to configure which attributes to track (defaults to logging events without attribute changes).

```php
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Article extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
```

### `CausesActivity`

Add to user/causer models. Provides `activitiesAsCauser()` relationship.

### `HasActivity`

Combines `LogsActivity` and `CausesActivity`. Provides `activities()`, `activitiesAsSubject()`, and `activitiesAsCauser()`.

## Manual Logging

```php
activity()
    ->performedOn($article)
    ->causedBy($user)
    ->event(ActivityEvent::Updated)
    ->withProperties(['key' => 'value'])
    ->log('Article was updated');
```

## LogOptions Methods

| Method | Description |
|--------|-------------|
| `logFillable()` | Log all fillable attributes |
| `logAll()` | Log all attributes |
| `logOnly(array)` | Log specific attributes |
| `logExcept(array)` | Exclude attributes |
| `logOnlyDirty()` | Only log changed attributes |
| `dontLogEmptyChanges()` | Skip logging when no tracked attributes changed |
| `dontLogIfAttributesChangedOnly(array)` | Ignore updates that only change these attributes |
| `useLogName(string)` | Set custom log name |
| `setDescriptionForEvent(Closure)` | Custom description per event |
| `useAttributeRawValues(array)` | Store raw (uncast) values |

## Querying Activities

```php
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Enums\ActivityEvent;

Activity::forEvent(ActivityEvent::Created)->get();
Activity::causedBy($user)->get();
Activity::forSubject($article)->get();
Activity::inLog('orders')->get();
```

## Setting the causer

Override the causer for a block of code:

```php
use Spatie\Activitylog\Facades\Activity;

Activity::defaultCauser($admin, function () {
    // all activities here are caused by $admin
});

// or set globally for the rest of the request
Activity::defaultCauser($admin);
```

## Disabling Logging

```php
activity()->withoutLogging(function () {
    // no activities logged here
});
```

## Accessing Changes and Properties

```php
$activity = Activity::latest()->first();

// Tracked model changes (set automatically by LogsActivity)
$activity->attribute_changes; // Collection: {"attributes": {...}, "old": {...}}

// Custom user data (set via withProperties)
$activity->properties; // Collection
$activity->getProperty('key'); // single value
```

## Custom Activity Model

Set `activity_model` in `config/activitylog.php` to a class that extends `Model` and implements `Spatie\Activitylog\Contracts\Activity`. Use a custom model for custom table names or database connections.

## Customizing Actions

The package uses action classes (`LogActivityAction`, `CleanActivityLogAction`) that can be extended and swapped via config:

```php
// config/activitylog.php
'actions' => [
    'log_activity' => \App\Actions\CustomLogActivityAction::class,
    'clean_log' => \App\Actions\CustomCleanAction::class,
],
```

Custom action classes must extend the originals. Override protected methods (`save()`, `beforeActivityLogged()`, `resolveDescription()`, etc.) to customize behavior.

## Configuration

Key config options in `config/activitylog.php`:
- `enabled`: Master on/off switch (env: `ACTIVITYLOG_ENABLED`)
- `clean_after_days`: Days to keep records for `activitylog:clean` command
- `default_log_name`: Default log name (string)
- `default_auth_driver`: Auth driver for causer resolution
- `include_soft_deleted_subjects`: Include soft-deleted subjects
- `activity_model`: Custom Activity model class
- `default_except_attributes`: Globally excluded attributes
- `actions.log_activity`: Action class for logging activities
- `actions.clean_log`: Action class for cleaning old activities

=== spatie/laravel-medialibrary/core rules ===

## Media Library

- `spatie/laravel-medialibrary` associates files with Eloquent models, with support for collections, conversions, and responsive images.
- Always activate the `medialibrary-development` skill when working with media uploads, conversions, collections, responsive images, or any code that uses the `HasMedia` interface or `InteractsWithMedia` trait.

=== spatie/guidelines-skills/core rules ===

# Project Coding Guidelines

- This codebase follows Spatie's coding guidelines.
- Always activate the `spatie-laravel-php` skill when writing, editing, reviewing, or formatting Laravel or PHP code.
- Always activate the `spatie-javascript` skill when writing, editing, reviewing, or formatting JavaScript or TypeScript code.
- Always activate the `spatie-version-control` skill when creating commits, branches, or managing Git operations.
- Always activate the `spatie-security` skill when configuring security, signing commits, reviewing authentication, or setting up servers and databases.

</laravel-boost-guidelines>
