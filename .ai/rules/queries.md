---
paths:
  - 'app/Queries/**'
  - 'app/Data/ListQuery.php'
  - 'app/Mcp/Tools/BaseListTool.php'
  - 'packages/Chat/src/Tools/BaseReadListTool.php'
  - 'packages/*/src/Queries/**'
---

# Queries: the read path

A reusable read is a query class. An action is a write. One filter language answers a list
question the same way on the REST API, MCP and chat. It lives in `app/Queries`. The design
records are `docs/superpowers/specs/2026-10-02-crm-filter-language-design.md` for the filter
language and `docs/superpowers/specs/2026-10-06-queries-read-layer-design.md` for the read path.

## Where a file goes

The layout follows spatie/laravel-query-builder's own `src/`.

| Kind of class | Folder |
|---|---|
| implements Spatie's `Filter` | `app/Queries/Filters` |
| implements Spatie's `Sort` | `app/Queries/Sorts` |
| a trait those classes share | `app/Queries/Concerns` |
| registry, tree validation, vocabulary | `app/Queries` |
| one entity's list, or a read across models | `app/Queries/<Domain>`, named `*Query` |
| the contract and the shared body of the five list queries | `app/Queries/Contracts/EntityQuery.php`, `app/Queries/Concerns/ListsEntity.php` |
| a read over a package's own models | `packages/<Name>/src/Queries`, named `*Query` |
| an enum | `app/Enums` |

`tests/Arch/ArchTest.php` fails a class in `Filters` or `Sorts` without its interface, and a
`Filter` or `Sort` anywhere else in `app/Queries`. `tests/Arch/ConventionsTest.php` fails one
without the `Filter` or `Sort` suffix. The Pest Laravel preset fails an enum outside
`app/Enums`.

`tests/Arch/ConventionsTest.php` fails a class in a domain folder of `Queries` without the
`Query` suffix ("names a class in a domain folder of Queries with the Query suffix"). It also
fails a `List*`, `Find*`, `Search*`, `Get*` or `Aggregate*` class anywhere under `Actions`
("keeps reads out of the Actions folders"). That gate reads names, so a reviewer reads for a
read named otherwise, such as `ResolveX` or `FetchX`.

`tests/Arch/ArchTest.php` already makes every `App` class `final` and `readonly` ("avoid open
for extension" and "avoid mutation"), so `app/Queries` needs no gate of its own. A package's
`Queries` folder joins the package service layers ("package service layers avoid mutation"
and "package service layers avoid inheritance"), and Pint's `final_class` keeps it `final`.
The arch test finds each package's `Queries` folder itself, so a new one is covered without
an edit.

The folders name roles, not layers. `EntityFilters` builds the classes in `Filters`, and they
use `Operand` and `FilterErrors` from the root.

## When a read becomes a query class

- **One model and one condition.** A `#[Scope]` on the model, such as
  `AgentConversation::ownedBy()`.
- **Two callers, a read across models, or a read that carries the filter grammar.** A query
  class.
- **One caller.** The read stays inline in that caller.
- **A read over a package's own models.** It lives in that package's `Queries` folder, whoever
  calls it. `ConversationsQuery` stays in Chat although the dashboard calls it, because
  `app/Queries` may not import a package.

No test tells a one-model predicate from a read that needs a query class, so a reviewer reads
for a scope written as a query class.

## The shape of a query class

- It is `final readonly`.
- It takes the acting `User`. `EntitiesByFieldValueQuery` is the one exception. It takes a
  `CustomField` and bounds both of its subqueries by that field's `tenant_id`, so its caller
  must resolve the field inside the acting workspace.
- It reads no ambient user, request or workspace, because chat tools run in queued jobs. The
  `ArchTest` tests "takes the acting user and reads no ambient user, request or workspace" fail
  `auth()`, `request()`, the `Auth` and `Request` facades, an injected `Request`, `Filament` and
  `CurrentWorkspace` under `app/Queries` and each package's `Queries` folder. One exception is
  deliberate: `paginate()` lets Laravel's paginator read the page and the cursor from the
  current request.
- It never writes. `EloquentWriteOutsideActionRule` (PHPStan) fails a write through a model, an
  Eloquent builder or a relation under each `Queries` folder. `ConventionsTest` fails a folder
  that `phpstan.neon` does not list ("guards every Queries folder against writes in
  phpstan.neon"). The rule does not read a write through Spatie's builder or the `DB` facade, so
  a reviewer does.
- A list query authorizes with `viewAny` and bounds itself to the workspace inside the trait, so
  a transport cannot forget either. `tests/Feature/CRM/SurfaceParityTest.php` fails a surface that
  lists for a user the policy denies ("refuses the list to a user with an unverified email on
  the api, mcp and chat").
- `paginate()` returns a page. `get()`, `find()`, `recent()` and `search()` return results. The
  builder behind `paginate()` is the private `for()`.

The five list queries (`CompaniesQuery`, `PeopleQuery`, `OpportunitiesQuery`, `TasksQuery` and
`NotesQuery`) implement `App\Queries\Contracts\EntityQuery` and use
`App\Queries\Concerns\ListsEntity`. The shape is an interface plus a trait, not a base class,
because three arch tests forbid inheritance in `App` ("avoid open for extension", "ensure no
extends" and "avoid inheritance" in `tests/Arch/ArchTest.php`).

Each list query declares two facts: `fields()` and `includes()`. A count include is a name in
`includes()` that ends in `Count`, and the query builder derives the count from the name. The
trait owns `entity()`, `sorts()` and `paginate()`. `entity()` reads
`CrmEntity::query()`, the one owner of which query lists which entity. Each transport maps its
own input to `App\Data\ListQuery`, a plain readonly class, and calls `paginate()`.

## The language imports no transport

`app/Queries` never uses `App\Mcp`, `App\Http`, `App\Filament`, `App\Livewire`, `App\Scribe`
or `Relaticle\Chat`. The surfaces import it. The arch test `the query language uses no
transport` fails the reverse.

A new surface gets its adapter on its own side. A panel filter lives under `app/Filament` and
imports `App\Queries`.

## One owner per fact

- `EntityFilters::definitions()` owns the filter names each entity accepts. A list query never
  registers an `AllowedFilter` of its own. `ListsEntity::for()` is the one place that does.
  `tests/Feature/CRM/SurfaceParityTest.php` fails a surface that publishes other names
  ("publishes exactly the filter names the list query accepts for each entity").
- `CustomFieldFilterSchema::operatorsForType()` owns the operators per field type. A native
  field takes the operators of the custom field type it maps to.
- `FilterTree` owns the tree limits: `MAX_CONDITIONS`, `MAX_LOGIC_DEPTH`, `MAX_HOPS`.
  `CustomFieldFilterSchema::MAX_LIST_VALUES` owns the cap on a list operand.
  `EntityFilters::limits()` publishes all four.
- `ListQuery::MAX_PAGE` owns the largest page a list serves. REST rejects a larger one, the
  chat tools clamp it, and the MCP list tool and the API reference read the constant.
  `tests/Feature/Api/V1/ListFilterTest.php` fails a REST list that takes one ("rejects a page
  number past the last one a list serves"), and `tests/Feature/Chat/ListToolFilterTest.php`
  fails a chat tool that throws on one ("serves an empty page for a page number no list can
  reach").
- `FilterVocabulary` builds what one workspace can filter on, and MCP and chat render it. The
  API docs render `EntityFilters::grammar()`, which needs no workspace.

Never write a filter name, an operator or a limit by hand in a tool description.
`tests/Feature/CRM/SurfaceParityTest.php` fails a surface that drifts: "publishes exactly
the filter names the list query accepts for each entity" and "states every filter limit
from the constants on every surface".

The MCP guide is the exception. `packages/Documentation/resources/content/docs/guides/mcp.md`
lists the names, the operators and the limits by hand.
`tests/Feature/Documentation/McpGuideFilterParityTest.php` fails when its names table, an
operator list in its operator table, or a limit drifts from the registry. No test reads which
field type a row names, the relation and `domain` operator sentences, or the sample error in
the REST guide. Update those by hand.

## Adding to it

- **A filter name.** Add one line to `EntityFilters::definitions()`. The API reference, MCP
  and chat pick it up. The MCP guide does not: `McpGuideFilterParityTest` fails until its
  table lists the name.
- **A kind of condition.** Add a `FilterKind` case and a class in `Filters`. PHPStan fails a
  `match` over `FilterKind` that has no `default` arm and misses the case.
  `FilterDefinition::operand()`, `NativeFilter` and `FilterTree::walk()` branch on the kind
  without that guard, so read them by hand.
- **An operator.** Add it to `operatorsForType()` and compile it in
  `CustomFieldFilter::applyCondition()`. A native field shares the type's operators, so
  compile it in `NativeFilter` too: its `text()` treats every operator but `$contains` as
  equality. `ListFilterSurfacesTest` fails until its operator table lists it ("publishes
  exactly the operators of each custom field type"), and then until a case runs it on every
  surface ("applies every operator of every custom field type alike on the api and mcp").
  A native field that shares the type fails the same way until a case runs the operator on
  it ("filters every native field of every entity alike on the api and mcp").
  `McpGuideFilterParityTest` fails until a row of the guide's operator table holds the new
  list.
- **A field or an include on a list.** Add it to the entity's query class. A count include is a
  name ending in `Count`. The API reference reads the includes and the sorts from the class. It
  does not list the fields.
- **A filter parameter on a list tool or endpoint.** Do not add one. A list takes filters only
  as the `filter` tree. `FilterTree::rejectUnknownArguments()` rejects a flat parameter, and
  `FilterTree::REPLACED` names the tree form of each retired one.
  `tests/Feature/Chat/ListToolFilterTest.php` and `tests/Feature/Mcp/McpReadToolsTest.php` are
  the gate ("rejects an argument a list tool does not take instead of listing every record").
- **A caller that wants more than a page.** None exists, so `ListsEntity::for()` is private.
  Make it public in the change that adds the first caller, with a test for that caller. The
  builder is authorized and workspace-bound already. Refine it with `where` and `whereHas`
  only, and put any `or` inside a `where(fn ...)` group. A top-level `orWhere` escapes the
  workspace bound wherever `CurrentWorkspace` is not set, such as a queued job. Never call
  `withTrashed()` or `withoutGlobalScopes()` on it. Never rebuild the allowlists in the caller:
  `tests/Arch/ArchTest.php` fails a Spatie query built outside a query layer ("builds a list
  query only in a query layer").

## What the SQL must keep

- Pass the acting `User` in. Never read `auth()` inside `app/Queries`: chat list tools run in
  queued jobs. Every relation subquery and every `$not` complement bounds itself to
  `$user->currentWorkspace`. A custom field subquery is bound by a field id resolved from
  that workspace. `ListFilterSurfacesTest` is the gate ("never reaches another workspace
  through a relation or a member id on the api and mcp").
- `$not` is a set complement: `NOT EXISTS` over the matching keys. It never compiles as SQL
  `NOT (...)`, which drops a row whose column is null. `ListFilterTest` is the gate ("returns
  records with an empty value under $not").
- `$not_in` and `$has_none` keep a record whose value is empty too. A relation and a custom
  field compile them as `NOT EXISTS`. A native column compiles `$not_in` as `whereNotIn` with
  `orWhereNull`.
- A scoped query takes no table alias. `whereKey()` and `whereRelation()` qualify columns
  with the table name, which Postgres rejects under an alias.

## Errors

A rejection is a `ValidationException` from `FilterErrors::at()`, keyed by the path of the
node to fix. Its message lives under `validation.filter.*` or `validation.custom_field.*` in
`lang/en/validation.php` and names the fix, because an agent acts on the message it reads.

`FilterTree::validate()` runs before the query builder is built. Several checks exist in both
the pre-pass and the filter classes. Change both, or the same mistake returns two different
errors. `tests/Feature/Api/V1/ListFilterTest.php` pins the keys and messages.
