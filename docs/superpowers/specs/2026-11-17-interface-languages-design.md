# Relaticle in German, Spanish and French

Date: 2026-11-17. Status: design, four decisions made by Manuk on 2026-11-17, waiting for approval of this document.

## Why

People who work in German, Spanish or French should be able to use Relaticle in their own language,
from the first visit to relaticle.com through signup, the app, its emails, Rela and the help pages.

Three outside contributors translated Relaticle on their own because the product could not do it:

- Issue #1220 (EnjoyJazz, 2026-11-16): a full German translation, made for personal use, offered as a PR.
- The fork `srjingles/crm.srjingles.com` (srchema, author of merged #928, 2026-10-05): Spanish (Spain).
- PR #758 "Feat/espanol" (Guillermoj9, 2026-09-13): 27 Spanish files, closed by its author ten minutes later.

Users ask for it too. In the Rela production audit a Russian-speaking user asked Rela to change the
interface language and was told the setting does not exist. Rela already answers in the language
the user types, so today a German user gets German replies inside an English app.

### Which three

Fathom visits to relaticle.com, 2026-09-16 to 2026-11-17, 5,189 visits mapped from country to
language (method in `~/.claude/research/relaticle-locale-list-evidence-2026-09.md`):

| Language | Share | | Language | Share |
|---|---|---|---|---|
| German | 7.5% | | Brazilian Portuguese | 3.9% |
| Spanish | 6.1% | | Dutch | 3.8% |
| French | 4.2% | | Simplified Chinese | 3.3% |

These are site visitors, not signed-up users. The same three led the September measurement.

## Goals

1. A signed-in user picks German, Spanish, French or English in their profile, and every surface
   they see follows it: the app panel, Rela's chrome and replies, emails, in-app notifications,
   dates and numbers.
2. relaticle.com serves every marketing page, help page, developer guide and blog post in all three
   languages at its own indexable URL (`/de/pricing`, `/es/help/...`, `/fr/blog/...`).
3. A visitor who reads the site in German and signs up lands in a German app without touching a setting.
4. No English string is left on any of those surfaces in any of the three languages.
5. It stays that way: a change that adds an English string without its three translations fails CI.

## Non-goals

- More languages than these three. The structure takes a fourth by adding files and a config entry.
- The system admin panel (`packages/SystemAdmin`). It is internal and stays English.
- Text written for agents and machines: MCP tool names, descriptions and schemas, REST API error
  bodies, the OpenAPI spec, `llms.txt`, console output, logs and exception messages.
- Users' own data: custom attribute names, option labels, record content and email bodies.
- Per-workspace languages. The language belongs to the user.
- A translation hub (Crowdin, Weblate). Translations live in the repo.

## Anti-goals (a failure even if it technically works)

- A mixed-language screen: a German panel with English buttons, labels or empty states.
- Changing any existing English URL. English pages keep their URLs and their rankings.
- Redirecting a visitor or a crawler by `Accept-Language`. Search engines crawl from US IPs in English.
- Product words drifting between screens: "Arbeitsbereich" on one page, "Workspace" on the next.

## What exists today

- PR #276 (May): app panel strings go through `__()`. `HardcodedUserFacingStringRule` and
  `HardcodedStaticPropertyRule` guard them. `php artisan locale:diff <locale>` reports missing,
  orphaned and stale keys, but is not wired to CI. `LocaleServiceProvider` sets Carbon and `Number`
  from the app locale. `docs/i18n.md` says main ships English only.
- The locale is one `APP_LOCALE` per install. Nothing reads `Accept-Language`, a cookie or a user setting.
- PR #684 (closed unmerged) and its branch `origin/feat/chat-user-language`, 15 commits, 5,100 behind
  main. It built `users.locale`, `SetUserLocale` middleware, `SeedUserLocaleListener` (from
  `Accept-Language` on first login), `config('app.available_locales')` with 14 codes, a profile
  select, `App\Support\LocaleScope` around the chat job, and a `## Language` block in `CrmAssistant`.
  Reuse its design. Rebuild it on main rather than merging the branch.
- Existing translations: `lang/fr` (37 keys, a sample from #276), `lang/vendor/activity-log/fr`.
  No `lang/*.json` exists, so every sentence key (`__('Save changes')`) renders English under any locale.
- Vendor coverage: Filament ships de, es and fr. `relaticle/flowforge` has de and es, no fr.
  `relaticle/custom-fields` and `relaticle/activity-log` have en only. `spatie/laravel-health` has all three.
- Framework strings (`validation`, `auth`, `passwords`, `pagination`) exist in `lang/en` only.
  laravel-lang was dropped in `025b19541` because it pulls abandoned packages.

### Gaps that still show English under a non-English locale

- `phpstan.neon:33-83` ignores the i18n rules for `app/Notifications`, `NotifyTaskAssignees`,
  `BaseLivewireComponent`, `packages/ImportWizard`, `packages/Chat/src/Tools`, `app/Console` and `app/Mcp`.
- Enums: about 500 literal match arms across 58 files in `app/Enums` and `packages/*/src/Enums`.
  Not all are labels: `BillingStatus`, `StripeSubscriptionStatus`, `CreationSource`, `OnboardingUseCase`,
  `CustomFieldType` and `ProposalEntity` hold the user-facing ones.
- Chat: literals in `PendingActionService.php:505,548,566,932`, `ProposalEditor.php:258-269`,
  `TipTapDocumentParser.php:53,57`, and `Str::headline()` labels in `BaseReadListTool.php:508-569`.
- JavaScript: English defaults in `packages/Chat/resources/js/chat/stream.js:23-78`, `send.js:25-27`,
  `transcript.js:1260,1275`, `chat-editor.js:74`, `chat-mention-suggestion.js:8`,
  `packages/Cloud/resources/js/documentation.js:168`.
- Mail: `User` does not implement `HasLocalePreference`, so queued mail and notifications render in
  the worker's locale.
- Marketing: about 39 literal lines in 12 views, the homepage FAQ array
  (`marketing/home/index.blade.php:11-37`), `resources/data/competitor-facts.php`,
  `config/comparisons.php`, `ConnectableAssistant` labels, and `resources/markdown/{policy,terms}.md`.
- Docs: `DocsRepository::build()` requires exactly `{area}/{category}/{slug}.md` and throws on
  anything deeper. `DocUrl` hardcodes `/help` and `/developers`.
- Blog: `relaticle/ink` (v2.5.1, public, `relaticle` org) has no locale concept. Post slugs are
  unique across the table, the controller looks posts up by slug alone, and the model class is
  not swappable.

### Volume per language

| Surface | Words |
|---|---|
| App: `lang/en` (2,342 keys), about 440 sentence keys, ImportWizard and Cloud app strings | about 10,000 |
| Marketing views and data | about 18,000 |
| Help (52 files) | 19,791 |
| Developer docs (5 files) | 11,453 |
| Blog (37 published posts) | about 50,000 |

About 110,000 words per language, 330,000 in total.

## Approaches

### Delivery

| | Approach | Trade-off |
|---|---|---|
| A | One spec, one plan, one PR covering every surface | One review and one release. A very large diff whose translation files cannot be read line by line in review |
| B | One PR per surface, app first | Each step ships and gets used. The site and app stay mixed-language until the last PR |

Picked: A, by Manuk on 2026-11-17. The plan builds it in the order below, so each step is
testable before the next starts, and the PR merges only when all of them are done. The one
exception is the blog's prerequisite PR in `relaticle/ink` (see Blog translations).

### Blog translations

| | Approach | Trade-off |
|---|---|---|
| A | Add `locale` and a translation group to `relaticle/ink`, release it, bump it here | The right home, and the FilaForms blog gets it too. It needs a second repo and a release before this PR can use it |
| B | Keep ink untouched. Cloud owns a `blog_post_translations` table and its own routes, views and MCP tools for translated posts | Fits one PR. A second place that knows what a post is, and ink's listings, search and tags never see the translations |

Picked: A, by Manuk on 2026-11-17 (option A of five). B builds a parallel blog inside Cloud that every later ink feature must be
copied into, which breaks "one fact, one owner". The ink change is a prerequisite PR in
`relaticle/ink`, released before this PR merges. This PR bumps the version. The single PR
covers this repository. Wrong if ink cannot release before this PR is ready, in which case the
blog part waits for it and nothing else does.

## Design

### Languages and the user's choice

- `config('app.available_locales')` is `['en', 'de', 'es', 'fr']`. One owner: every picker, route
  constraint, validator and test reads it.
- `users.locale`: nullable string. `User::effectiveLocale()` returns it when it is listed, otherwise
  `config('app.default_locale')` (`APP_LOCALE`), a key nothing overwrites at runtime. `User` implements `HasLocalePreference`, so Laravel sends mail and
  notifications, queued ones included, in the recipient's language.
- Profile settings get a Language select. Options show each language in its own name (Deutsch,
  Español, Français, English).
- `SetUserLocale` middleware sets the app, `Date` and `Number` locale for the signed-in user. It runs
  in the web group and as panel middleware. It also runs as persistent Livewire middleware, as
  `ReadAcrossWorkspaces` does.
- A guest's language on app pages (sign-in, sign-up, password reset, invitation acceptance) comes from:
  the `locale` cookie set by the public site, then `Accept-Language` matched against the list, then English.
- Sign-up stores the guest's language on the new user. An existing user with no `locale` gets it
  seeded once from `Accept-Language` on login, as `SeedUserLocaleListener` did on #684's branch.
- Jobs: a job dispatched in a request carries the locale through Laravel `Context`, the way the
  workspace id travels. A job acting for a specific user (chat turn, export notice) runs inside
  `LocaleScope::within($user->effectiveLocale(), ...)`, which restores the previous locale in `finally`.
- Self-hosted: `APP_LOCALE` stays the default for users who have not chosen. All four languages
  ship in the core repository.

### App strings

- Translations for `de`, `es` and `fr` mirror `lang/en` file for file. Sentence keys get
  `lang/{de,es,fr}.json`. ImportWizard gets `packages/ImportWizard/resources/lang/{de,es,fr}`.
  Cloud app strings (billing, mail, inactivity) get `packages/Cloud/lang/{de,es,fr}`.
- Framework strings: generate `validation`, `auth`, `passwords` and `pagination` for the three
  languages once with laravel-lang, installed only for that run as `docs/i18n.md` describes, then
  review and commit them. No new dependency.
- Vendor strings that ship without these languages are published and translated under `lang/vendor/`:
  custom-fields (de, es, fr), activity-log (de, es), flowforge (fr).
- Close every gap listed above: wrap the enum labels, chat literals, JavaScript defaults (pass them
  from Blade, as `chat-interface.blade.php` already does for `toolLabels`), and the user-facing
  strings under the `phpstan.neon` ignores. Then remove those ignore entries, except `app/Console`
  and `app/Mcp`, which are machine-facing (non-goal).
- Tool proposals keep the strings of the locale active when they were made. A later language switch
  never rewrites an old card, and replayed tool results are never rewritten.

### Built-in attributes, stages and sample data

Decided by Manuk on 2026-11-17 (option A of five): a new workspace gets its built-in custom
attributes, their options (the ten opportunity stages) and its sample records in the language of
the user who creates it. From then on they are workspace data, edited and shown as stored, like
any other user data. Existing workspaces keep their English rows.

- `CreateWorkspaceCustomFields` reads the creator's `effectiveLocale()`. Display names and option
  labels in `app/Enums/CustomFields/*Field.php` go through `__()`, and so do the option colour
  keys, so a German stage keeps its colour.
- `packages/OnboardSeed/resources/fixtures` get German, Spanish and French versions, picked by the
  same language.
- Rejected: translating at display time (reaches every surface that shows a field name and fights
  renames), storing a name per language on the row (changes the custom-fields package schema), a
  workspace data-language setting (nobody has asked for it yet; it can be added on top of this
  later), and leaving English (the mixed screen this design exists to remove).

### Recognising a finished task in every language

Decided by Manuk on 2026-11-18 (option A of five). Seven places find the task status "Done" by its
English name (`TaskField::DONE_STATUS`): `CompleteTask`, `DigestService`, `MyTasksService`,
`CrmSummaryQuery`, `RecordOverview` and two in `CustomFieldOptionPlan`. Issue #556 records why
that is fragile. A German workspace seeded with "Erledigt" would never count a task as done.

- One owner on `TaskField`: `doneNames()` returns the "Done" label in every available language,
  and `isDone(string $name)` tests a name against it. The seven call sites use them, with
  `whereIn` in the SQL ones.
- A rename still breaks it, exactly as it does today. Giving options a stable meaning stays with
  #556, and an app-side option-meaning table (option C) is the step that fixes renames too.
- Prior art, read 2026-11-18: Twenty stores a stable option `value` (`DONE`) on records and never
  matches labels. It ships 35 locales but does not translate option labels yet (its issue #27239 is
  open). Attio models a finished task as an `is_completed` boolean, and its default deal stages are
  English titles with no outcome flag. Neither translates seeded options, so this design goes
  further than both.

### Rela

- Replies follow the language of the user's latest typed message (today's rule). A turn the
  system opened (the resume after a decision, the setup greeting, records arriving) follows the user's
  last typed message, and the chosen language only when they have typed nothing yet. The dynamic
  part of the prompt carries a `## Chosen language` block, shown only when the user's turn carries
  no text (system-opened, or an attachment with no message), so the cached prefix still hits.
- `ConversationTitler` and `NextStepSuggester` take the same language.
- `SearchDocsTool` searches the help index in the user's language first, then English.

### Public site URLs

- English keeps its URLs, and an English URL always renders English, whatever the visitor's cookie,
  browser or profile says. German, Spanish and French pages live under `/de`, `/es` and `/fr`:
  `/de/pricing`, `/es/help/{category}/{slug}`, `/fr/developers/{type}`, `/de/blog/{slug}`,
  `/fr/compare/relaticle-vs-{competitor}`.
- Slugs stay English in every language (call). Localized slugs would mean a slug map per page and
  per post, and the language prefix already tells search engines which language a page is.
- One owner turns a route into its URL in the current language. Every `route()` call in the public
  views goes through it, so a German page links to German pages.
- A middleware sets the locale from the prefix and writes the `locale` cookie.
- Every public page emits `<link rel="alternate" hreflang>` for all four languages plus `x-default`
  (the English URL). The canonical URL is the page's own language URL. `<html lang>` and `og:locale`
  follow the page language.
- `sitemap.xml` lists every page in every language, with `xhtml:link` alternates.
- The marketing footer and the docs shell get a language switcher that links to the same page in
  the other languages. The screenshot reference is the language menu on another CRM's marketing
  footer: own name on the left, English name on the right, a check on the current one.
- No redirect by browser language, and no automatic banner (anti-goal above).
- Markdown responses (`ProvideMarkdownResponse`) serve the page in its URL's language.
- `/blog/feed` stays English. Each language gets its own feed at `/{locale}/blog/feed`.

### Marketing copy

- Wrap the remaining literals, the FAQ array, `ConnectableAssistant` labels,
  `competitor-facts.php` and `config/comparisons.php` copy in `__()`.
- Cloud's sentence translations live in `packages/Cloud/lang/{de,es,fr}.json`, loaded with
  `loadJsonTranslationsFrom` in `CloudServiceProvider`. Core `lang/` never holds Cloud copy, which
  keeps `ConventionsTest`'s "keeps the cloud package out of core" check true.
- Terms of Service and Privacy Policy stay in English in every language, under the language
  prefix, with one translated line saying the English text governs (call). A translated contract
  is a second binding text, and nobody on the team can vouch for a legal translation.

### Docs and help

- Translated content sits beside the English tree:
  `packages/Documentation/resources/content/{de,es,fr}/{help,docs}/{category}/{slug}.md`.
  English stays where it is.
- `DocsRepository` builds one manifest and one search index per language. `DocUrl` takes the
  language and builds prefixed URLs. `HelpController`, `DocumentationController`, `DocsNavigation`,
  `BuildSearchIndex` and `GenerateSitemapCommand::addDocumentationUrls()` follow.
- Every English page has a counterpart in each language with the same path, title and description
  fields, and a test fails a missing one.
- YAML front matter values get no bare `: `, because one throws a ParseException that takes down
  every help page (`writing.md`).

### Blog

- Ink (prerequisite PR in `relaticle/ink`): posts get `locale` (default `en`) and a
  `translation_group`. The slug is unique per locale. Routes take an optional language prefix.
  `Post::getUrl()`, related posts, breadcrumbs, the feed's `<language>`, the sitemap generator and
  hreflang follow the post's language. The MCP create and update tools accept `locale` and
  `translation_of`.
- This repository bumps ink and registers the prefixed blog routes for the three languages.
- Translating the 37 posts is content, not code. It happens right after deploy through the blog
  MCP server, the same way posts are written today. The PR description lists it as a launch step.
- A post with no version in a language answers 404 at that language's URL (call). Hreflang links
  and sitemap alternates list only the versions that exist. A language's blog index lists only its
  own posts, and its blog link in the navigation shows once it has a published post. No flag.
- A new English post gets its three versions when it is published. That is the publishing
  workflow's job (the `relaticle-ink` skill), because posts live in the database and CI cannot see them.

### Form of address and Spanish variant

Decided by Manuk on 2026-11-17 and corrected on 2026-11-18: German uses "Sie", French uses "vous",
Spanish is neutral Latin American with "usted". The first decision picked "tú" on the belief that
Filament's Spanish uses it. It does not: Filament v5.11.2's Spanish mostly addresses the user as
"usted" (75 forms against 22, the confirm modal reads "¿Está segura/o de hacer esto?"). "usted"
matches most of what Filament shows and the formality of German and French, with no override
files. Filament's own 22 "tú" lines stay as they are. Neutral Spanish uses no "vosotros" and no
word only one country uses ("ordenador", "computadora"). 62% of Spanish visits came from Latin
America (198 of 319, 2026-09-16 to 2026-11-17). The locale code stays `es`.

German writes the MCP server as "MCP-Server" (decided 2026-11-18), as it already writes
"API-Token". Spanish and French keep "MCP server" as the glossary says.

### Vocabulary

- `docs/i18n.md` gets a glossary: the word for workspace, member, company, person, opportunity,
  task, note, custom attribute, Rela (never translated), and the MCP server in each language. The
  translators use it and reviewers check against it.
- The writing rules apply to every language: no em-dash (`ConventionsTest` already scans `lang/`
  and `resources/`), one idea per sentence. The English retired-word checks stay as they are. Each
  glossary gets a "never use" column, and the plan decides whether that needs a test.

### Keeping it complete

- English sentence keys get a committed `lang/en.json`, built from the `__()` calls in the source
  (call). An arch test fails when the file differs from what a fresh scan finds, so a new
  `__('Some sentence')` cannot slip past. `packages/Cloud` gets its own `lang/en.json` the same way.
  Today `locale:diff` compares JSON keys against a `lang/en.json` that does not exist, so without
  this it sees no sentence key at all.
- `locale:diff` also reads `packages/*/lang`, `packages/ImportWizard/resources/lang` and the
  `lang/vendor` overrides.
- An arch test runs `locale:diff` for de, es and fr and fails on any missing or orphaned key. The
  docs test fails a missing page. Every later change that adds an English string adds its three
  translations in the same PR.
- `docs/i18n.md` is rewritten: main ships four languages, how to add a string, the glossary, and
  how a contributor reviews or adds a language.

### Translation and review

Decided by Manuk on 2026-11-17: all three languages go live at merge, fully enabled, with no
native-speaker gate. People who find a wrong or clumsy string fix it with a pull request.

- Starting point: EnjoyJazz's German (offered in #1220), against older code. srchema's Spanish fork
  was never offered, so Spanish and French start from scratch. Claude translates everything else with the glossary in context, then a second pass
  checks every file against the glossary and the writing rules.
- If EnjoyJazz opens the German PR in time, its files are merged into this branch with their
  authorship kept, and they must pass the CLA check. This PR never waits for it.
- `docs/i18n.md` tells a native speaker how to improve a language: which files hold it, the
  glossary, `locale:diff`, and that a translation fix is a normal pull request.
- Rejected: a native read of everything before merge, a native read of the app strings only,
  hiding a language until it is read, and paid reviewers.

## Testing

- Feature: a German user's panel pages, Livewire updates, notifications and queued mail render German;
  a guest with a `de` cookie gets a German sign-up page and the new user stores `de`; the profile
  select saves and clears; the chat job runs in the user's language and restores the worker's
  locale even when it throws; Rela's system-started turn carries the language block.
- Public site: every public GET route answers 200 under each prefix; hreflang, canonical,
  `html lang` and `og:locale` match the page; the sitemap lists alternates; links inside a German
  page stay German; English URLs are unchanged.
- Docs: every English page has its three counterparts; the per-language search index; Rela's
  search falls back to English.
- Arch: `locale:diff` clean for the three languages; no i18n ignore left except Console and Mcp.
- Browser: one walk per language through sign-up, the panel, Rela and a help page, plus the WebKit
  run the UI rules ask for.

## Left to the builder

- How the localized route registration is built (route groups per locale or one group with a
  constrained parameter), as long as English URLs and route names keep working.
- Where the URL owner class lives and its name.
- The migration's place in the timeline and the exact `Context` key for the locale.
- How enum labels are wrapped (lang file per enum or a shared file).
- Batch size and order of the translation passes.
- The cookie domain that carries the `locale` cookie from relaticle.com to the app host.
