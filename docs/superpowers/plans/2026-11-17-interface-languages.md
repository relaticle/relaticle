# Relaticle in German, Spanish and French Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use sdd-lean (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A user picks German, Spanish, French or English, and the app, Rela, emails, notifications, relaticle.com, the help and developer docs, and the blog all follow it, with CI failing any English string that ships without its three translations.

**Architecture:** One owner list, `config('app.available_locales')`, drives everything. Signed-in users carry `users.locale` (mirroring `users.timezone`), and `SetUserLocale` applies it per request, per Livewire update and per job. The public site keeps English URLs and registers the same routes again under `/de`, `/es` and `/fr`, with one URL owner class, hreflang alternates and a language switcher. Docs content gains a locale tree beside the English one. The blog gets languages from `relaticle/ink` 2.6, a prerequisite release.

**Tech Stack:** Laravel 13, Filament 5, Livewire 4, Pest 4, PostgreSQL, `relaticle/ink`, Laravel `Context`, `HasLocalePreference`.

**Spec:** `docs/superpowers/specs/2026-11-17-interface-languages-design.md`

## Global Constraints

- Locales: `['en', 'de', 'es', 'fr']`, read only through `config('app.available_locales')`. English is the default and keeps every existing URL, and an unprefixed public URL always renders English.
- The install's default language is `config('app.default_locale')` (env `APP_LOCALE`). Never read `config('app.locale')` as the default: `Application::setLocale()` overwrites it on every call.
- Address: German "Sie", French "vous", Spanish neutral Latin American with "usted" (no "vosotros", no one-country words like "ordenador" or "computadora"). Locale code `es`. German writes "MCP-Server".
- Every translation obeys the `docs/i18n.md` glossary (Task 9), and Rela is never translated.
- No em-dash (U+2014) in any file. The `ConventionsTest` copy checks apply to every language file.
- Core never names `Relaticle\Cloud`, `cloud::` or `packages/Cloud`. Marketing copy lives in `packages/Cloud/lang`.
- Out of scope, stays English: `packages/SystemAdmin`, MCP tool text, REST API error bodies, the OpenAPI spec, `llms.txt`, console output, logs, exception messages, users' own data.
- Migrations: `up()` only, no database clock. Actions are `final readonly` with one `execute()`. Code shape, comments and test rules follow `CLAUDE.md`.
- YAML front matter in `packages/Documentation/resources/content` never holds a bare `: ` inside a value.
- Local runs are scoped: `php artisan test --compact <files>` or `composer test:affected`. Never the whole suite locally.
- Every task ends with `vendor/bin/pint --dirty --format agent` before its commit.

## Review Focus

1. A stored locale that is no longer listed (or garbage from an old row) falls back to English and never throws. Pinned in Task 1.
2. A user who switches language sees the whole page in it straight away. Livewire's `SupportLocales` replays the locale a component memoized at mount, so the profile save reloads the page. Pinned in Task 3.
3. A queue worker that ran a German job runs the next job, which has no user, in the install default. Pinned in Task 2.
4. `Accept-Language: de-AT,de;q=0.9` resolves to `de`, and `pt-BR,pt;q=0.9` resolves to `en`. `/de` and `/de/` both serve the German homepage, `/developers` is never read as a `/de` URL, and `/pricing` stays English for a German user or a German browser. Pinned in Tasks 2 and 13.
5. A German page that links to something with no German version (a blog post not yet translated) links to the English URL, never to a German 404. Pinned in Task 16.

---

## Part 0: `relaticle/ink` 2.6 (separate repository, prerequisite)

### Task 0: Posts carry a language and a translation group

Work in a checkout of `github.com/relaticle/ink`, branch `feat/post-locales` from `2.x`. Its own PR, merged and tagged `v2.6.0` before Task 16 starts.

**Files:**
- Create: `database/migrations/2026_11_18_000000_add_locale_to_blog_posts_table.php`
- Modify: `config/ink.php`, `src/Models/Post.php`, `src/Models/Concerns/ReclaimsTrashedSlugs.php`, `routes/web.php`, `src/Http/Controllers/BlogController.php`, `src/InkServiceProvider.php`, `src/BlogSitemapGenerator.php`, `src/Components/MetaTags.php` + `resources/views/components/meta-tags.blade.php`, `resources/views/components/feed.blade.php`, `src/Components/RelatedPosts.php`, `src/Livewire/BlogSearch.php`, `src/Mcp/Tools/{CreatePostTool,UpdatePostTool,ListPostsTool,GetPostTool}.php`, `src/Filament/Resources/PostResource.php`
- Test: the package's existing test files for posts, routes, sitemap and MCP tools (extend them)

**Interfaces:**
- Produces: `blog_posts.locale` (string, default `'en'`), `blog_posts.translation_group` (uuid, nullable), unique `(locale, slug)` replacing unique `slug`.
- Produces: config `ink.locales` (`['en']` default) and `ink.default_locale` (`'en'`). Routes register once unprefixed for the default locale, and once per other locale with prefix `{locale}/{ink.prefix}` and names `blog.{locale}.index|show|category|tag|feed`.
- Produces: `Post::translations(): Collection<Post>` (same group, other locales, published), `Post::getUrl(): string` honouring the post's locale, scope `Post::inLocale(string $locale)`.
- Produces: MCP `create-post-tool` and `update-post-tool` accept `locale` (one of `ink.locales`) and `translation_of` (post id, sets the same group). `list-posts-tool` accepts a `locale` filter. `get-post-tool` returns `locale` and `translations`.

- [ ] **Step 1: Write failing tests**: `it('serves a German post at /de/blog/{slug} and its English sibling at /blog/{slug} with the same slug')`, `it('answers 404 for /de/blog/{slug} when only the English post exists')`, `it('lists only posts in the page locale on the index, category, tag and feed')`, `it('emits hreflang links for every published translation plus x-default')`, `it('adds xhtml:link alternates to the sitemap')`, `it('sets <language> in a locale feed')`, `it('creates a translation through MCP with translation_of')`, `it('refuses a locale outside ink.locales')`.
- [ ] **Step 2: Run them, expect FAIL** (missing column, missing routes).
- [ ] **Step 3: Implement.** The migration backfills `locale = 'en'` and leaves `translation_group` null. A post gets a group the first time a translation names it. `ReclaimsTrashedSlugs` reclaims per `(locale, slug)`. Related posts and search stay inside one locale.
- [ ] **Step 4: Run the package suite, expect PASS.**
- [ ] **Step 5: Commit, open the PR, merge after review, tag `v2.6.0`.**

---

## Part 1: The app

### Task 1: The language list and the user's preference

**Files:**
- Modify: `config/app.php:181-185` (add `available_locales` and `default_locale`), `app/Models/User.php` (fillable, `HasLocalePreference`)
- Create: `database/migrations/2026_11_18_000001_add_locale_to_users_table.php`
- Test: `tests/Feature/Auth/UserModelTest.php`

**Interfaces:**
- Produces: `config('app.available_locales'): list<string>` = `['en', 'de', 'es', 'fr']`, and `config('app.default_locale')` = `env('APP_LOCALE', 'en')`, a key nothing writes at runtime.
- Produces: `users.locale` string(10) nullable. `User::effectiveLocale(): string` (stored value when listed, else `config('app.default_locale')`), `User::preferredLocale(): string` (returns `effectiveLocale()`).

- [ ] **Step 1: Write failing tests** in `UserModelTest.php`: `it('reads a listed stored locale')` (`locale = 'de'` → `'de'`), `it('falls back to the install default for a null or unlisted locale')` (`null`, `'pt'`, `'zz'` → `'en'`, also after `app()->setLocale('fr')` ran earlier in the test), `it('sends notifications in the recipient language')` (`Notification::fake()`, notify a `de` user with `VerifyEmail`, `Notification::assertSentTo($user, VerifyEmail::class, fn ($n, $c, $u, $locale) => $locale === 'de')`).
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Auth/UserModelTest.php`. Expect FAIL.
- [ ] **Step 3: Implement** the config key, migration, fillable entry and the two methods.
- [ ] **Step 4: Run again.** Expect PASS.
- [ ] **Step 5: Commit** `feat(i18n): store a language per user and send mail in it`.

### Task 2: Apply the language to requests, Livewire updates and jobs

**Files:**
- Create: `app/Http/Middleware/SetUserLocale.php`, `app/Support/Locales.php`
- Modify: `bootstrap/app.php` (web group append), `app/Providers/Filament/AppPanelProvider.php:553-575` (panel and persistent middleware), `app/Providers/AppServiceProvider.php` (`Livewire::addPersistentMiddleware`, `Context::hydrated`), `app/Providers/LocaleServiceProvider.php` (move its Date/Number calls into `Locales::apply()`)
- Test: `tests/Feature/Middleware/SetUserLocaleTest.php`

**Interfaces:**
- Produces: `Locales::apply(string $locale): void` (sets app, `Date` and `Number` locale), `Locales::fromHeader(?string $acceptLanguage): ?string` (first listed primary subtag by q-order), `Locales::within(string $locale, Closure $callback): mixed` (restores the previous app, Date and Number locale in `finally`), `Locales::COOKIE = 'locale'`.
- Produces: `SetUserLocale` resolves the user's `effectiveLocale()`. For a guest it tries the `locale` cookie, then `Locales::fromHeader()`, then `config('app.default_locale')`. It calls `Locales::apply()` and `Context::addHidden('locale', $locale)`.
- Produces: `Context::hydrated` applies a hidden `locale` when present, and `config('app.default_locale')` when absent. That reset is what stops a worker leaking one job's language into the next.

- [ ] **Step 1: Write failing tests**: `it('renders the panel in the user language')` (a `de` user's `/companies` response carries `<html lang="de"`, which Filament's base layout takes from the app locale), `it('uses the cookie then Accept-Language for a guest')` (dataset: cookie `es` → `es`; header `de-AT,de;q=0.9` → `de`; header `pt-BR,pt;q=0.9` → `en`), `it('carries the language into a queued job and resets it for the next')` (dispatch a test job from a `de` request, assert it sees `de`; run a second job with an empty context, assert `en`).
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Middleware/SetUserLocaleTest.php`. Expect FAIL.
- [ ] **Step 3: Implement** the support class, middleware and registrations.
- [ ] **Step 4: Run again, plus** `tests/Feature/Support/LocaleServiceProviderTest.php`. Expect PASS.
- [ ] **Step 5: Commit** `feat(i18n): apply the user language to requests, livewire and jobs`.

### Task 3: Choose it in the profile, keep it from sign-up, seed it on login

**Files:**
- Modify: `app/Livewire/App/Profile/UpdateProfileInformation.php:107-114` (Language select after timezone), `app/Actions/Fortify/UpdateUserProfileInformation.php` (validation and a `localeAttribute()` twin of `timezoneAttribute()`), `app/Actions/Fortify/CreateNewUser.php`, `app/Actions/Fortify/CreateNewSocialUser.php`, `lang/en/profile.php`
- Create: `app/Listeners/SeedUserLocale.php` (on `Illuminate\Auth\Events\Login`)
- Test: `tests/Feature/Profile/UpdateUserProfileInformationTest.php`, `tests/Feature/Auth/RegistrationTest.php`, `tests/Feature/Auth/SocialiteLoginTest.php`, `tests/Feature/Auth/AuthenticationTest.php`

**Interfaces:**
- Consumes: `Locales::fromHeader()`, `User::effectiveLocale()`.
- Produces: profile option labels are endonyms: `['en' => 'English', 'de' => 'Deutsch', 'es' => 'Español', 'fr' => 'Français']`, built from `Locale::getDisplayLanguage($code, $code)` with the first letter uppercased. Validation `['nullable', Rule::in(config('app.available_locales'))]`. An empty value clears it. A save that changes the language redirects to the profile page, so every component re-mounts in it.

- [ ] **Step 1: Write failing tests**: `it('saves and clears the language from the profile')`, `it('reloads the page in the new language after a change')` (assert the redirect, then `<html lang="fr"` on the next GET), `it('rejects a language outside the list')`, `it('stores the guest language on email sign-up')` (cookie `de` → `users.locale = 'de'`), `it('stores the guest language on social sign-up')`, `it('seeds a missing language from Accept-Language once on login')` (null + `fr-FR` header → `fr`; existing `de` stays `de`).
- [ ] **Step 2: Run** the four files. Expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run again.** Expect PASS. Then walk the profile page with agent-browser in light and dark.
- [ ] **Step 5: Commit** `feat(i18n): choose the language in the profile and keep it from sign-up`.

### Task 4: No English left in PHP: enums, notifications, ImportWizard, base components

**Files:**
- Modify: every enum in `app/Enums` and `packages/*/src/Enums` whose label, description or option methods return literals (start with `BillingStatus`, `StripeSubscriptionStatus`, `CreationSource`, `OnboardingUseCase`, `CustomFieldType`, `ProposalEntity`); `app/Notifications/**`; `app/Actions/**/NotifyTaskAssignees.php`; `app/Livewire/BaseLivewireComponent.php`; `packages/ImportWizard/src/**`; `lang/en/enums/*.php` (new, one file per enum); `phpstan.neon:33-83` (delete every i18n ignore except `app/Console`, `app/Mcp` and `packages/Chat/src/Tools`: tool names and descriptions are read by the model, and Task 5 covers the tool strings a user sees)
- Test: the existing tests of each touched class (find with `grep -rl '<ClassName>' tests packages/*/tests`)

**Interfaces:**
- Produces: enum labels read `__('enums/<enum-file>.<case value>')`. Machine values (`->value`) never change.

- [ ] **Step 1: Delete the i18n ignore entries** from `phpstan.neon`.
- [ ] **Step 2: Run** `vendor/bin/phpstan analyse`. Expect FAIL listing every hardcoded string.
- [ ] **Step 3: Wrap each reported string** and every literal label match arm in the listed enums (PHPStan does not guard enum methods, so read them).
- [ ] **Step 4: Run PHPStan** (0 errors) and `composer test:affected`. Expect PASS.
- [ ] **Step 5: Commit** `refactor(i18n): route enum, notification and import strings through the lang files`.

### Task 5: No English left in chat code and JavaScript

**Files:**
- Modify: `packages/Chat/src/Services/PendingActionService.php:505,548,566,932`, `packages/Chat/src/**/ProposalEditor.php:258-269`, `packages/Chat/src/**/TipTapDocumentParser.php:53,57`, `packages/Chat/src/Tools/BaseReadListTool.php:508-569` (labels through `__()` instead of `Str::headline()` for user-facing column titles), `packages/Chat/resources/js/chat/{stream,send,transcript}.js`, `packages/Chat/resources/js/chat-editor.js:74`, `packages/Chat/resources/js/chat-mention-suggestion.js:8`, `packages/Chat/resources/views/livewire/chat/chat-interface.blade.php:39-118`, `packages/Cloud/resources/js/documentation.js:168` + the docs view that loads it
- Test: `tests/Browser/Chat/LoadingShimmerTest.php`, the chat feature tests covering each touched service (`grep -rl`)

**Interfaces:**
- Produces: every JavaScript string a user sees arrives from Blade through `@js(__('...'))` on the component's config object. The JS keeps no English fallback.

- [ ] **Step 1: Write a failing test** in `tests/Feature/Chat/ChatChromeLocaleTest.php`: `it('renders the chat strings in the user language')` (a `fr` user, render `chat-interface`, assert the injected config holds `__('...')` values resolved in `fr` for the composer placeholder and two tool labels; use `app('translator')->addLines()` for fixture lines).
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the new test, `php artisan test --compact tests/Browser/Chat/LoadingShimmerTest.php`, and the touched services' tests. Expect PASS.
- [ ] **Step 5: Commit** `refactor(i18n): pass every chat and docs script string from the lang files`.

### Task 6: The English catalogue and the completeness gate

**Files:**
- Create: `app/Console/Commands/LangExtractCommand.php` (`lang:extract`), `lang/en.json`, `packages/Cloud/lang/en.json`, `tests/Arch/TranslationsTest.php`
- Modify: `app/Console/Commands/LocaleDiffCommand.php`, `app/Support/PestTiaRuntime.php` (`FILES_ARCH_TESTS_READ` gets `lang/` and `packages/*/lang`), `packages/Cloud/src/CloudServiceProvider.php:109` (`loadJsonTranslationsFrom(__DIR__.'/../lang')`)
- Test: `tests/Feature/Commands/LocaleDiffCommandTest.php`, `tests/Arch/TranslationsTest.php`

**Interfaces:**
- Produces: `php artisan lang:extract [--check]` scans `__()`, `trans()`, `@lang` and `@js(__())` calls with a literal first argument in `app/`, `resources/`, `packages/*/src` and `packages/*/resources`. A key is a group key when it has a `namespace::` prefix or its first dotted segment names an existing `lang/en/<group>.php` (or that package's group file). Every other key is a sentence key, periods included (`'Done. Northwind renewal is Closed Won.'`). A sentence key found under `packages/<Name>/` goes to `packages/<Name>/lang/en.json` when that package has a `lang/` directory, otherwise to `lang/en.json`. The command finds packages by glob and names none, and skips `packages/SystemAdmin`. `--check` writes nothing and exits 1 when any file differs from the scan.
- Produces: `locale:diff <locale> --lang-path=<dir>` keeps working. The arch test finds its roots by glob (`lang`, `packages/*/lang`, `packages/*/resources/lang`, `lang/vendor/*`) and skips any that do not exist, so the self-hosted CI job, which deletes `packages/Cloud`, still passes.
- Produces in `TranslationsTest.php`: `it('keeps the English catalogue in step with the source')` (runs `lang:extract --check`), `it('translates every English key into {locale}')` with dataset `config('app.available_locales')` minus `en` (fails until Tasks 10-12).

- [ ] **Step 1: Write the failing tests** above, plus in `LocaleDiffCommandTest.php`: `it('reports a sentence key missing from the locale json')`.
- [ ] **Step 2: Run** both files. Expect FAIL.
- [ ] **Step 3: Implement** the command, generate both `en.json` files, register Cloud's JSON path.
- [ ] **Step 4: Run.** Expect the catalogue test and the command test to PASS, and the three completeness rows to FAIL (they are Tasks 10-12's targets).
- [ ] **Step 5: Commit** `test(i18n): extract english sentence keys and gate every language on them`.

### Task 7: Rela speaks the user's chosen language when it starts a turn

**Files:**
- Modify: `packages/Chat/src/Agents/CrmAssistant.php:357-361` (rule wording) and `dynamicInstructions()` / `currentUserBlock():547` (adds the language), `packages/Chat/src/Jobs/ProcessChatMessage.php:152,537` (`Locales::within()` around `handle()` and `failed()`), `packages/Chat/src/Agents/ConversationTitler.php:49`, `packages/Chat/src/Agents/NextStepSuggester.php:64`
- Test: `tests/Feature/Chat/CrmAssistantInstructionsTest.php`, `tests/Feature/Chat/NextStepSuggestionTest.php`, `tests/Feature/Chat/ConversationLanguageTest.php` (create)

**Interfaces:**
- Consumes: `User::effectiveLocale()`, `Locales::within()`.
- Produces: the static rule reads "On a turn the system opened (a <turn> block is present), use the language of the user's last typed message, or the user's chosen language when they have typed nothing yet." The dynamic block names the language in English (`German`), so the cached static prefix is unchanged.

- [ ] **Step 1: Write failing tests**: `it('names the user language in the dynamic block, never the static one')`, `it('runs the chat job in the user language and restores the worker locale even when it throws')`, `it('gives the titler and suggester the user language as the fallback')`.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the three files. Expect PASS. `CrmAssistantInstructionsTest` must still pass the rule 11 wording check.
- [ ] **Step 5: Commit** `feat(chat): open system turns in the user's chosen language`.

### Task 8: New workspaces get built-ins and samples in the creator's language

**Files:**
- Modify: `app/Listeners/CreateWorkspaceCustomFields.php:39-98`, `app/Enums/CustomFields/{Company,People,Opportunity,Task,Note}Field.php` (`getDisplayName()`, `getOptions()`, `getOptionColors()` keyed by stable option keys), `packages/OnboardSeed/src/OnboardSeedManager.php:36`, `packages/OnboardSeed/src/ModelSeeders/*.php`
- Create: `lang/en/enums/custom-fields.php`, `packages/OnboardSeed/resources/fixtures/{de,es,fr}/` mirroring the English tree (only translatable text fields; company names stay)
- Test: the workspace creation and onboarding seed tests (`grep -rl 'CreateWorkspaceCustomFields\|OnboardSeedManager' tests packages/*/tests`)

**Interfaces:**
- Consumes: `Locales::within()`, `User::effectiveLocale()`.
- Produces: `TaskField::doneNames(): list<string>` (the `Done` option label in every `available_locales` language, read with `__($key, [], $locale)`) and `TaskField::isDone(string $name): bool`. `DONE_STATUS` is removed, and its seven readers switch: `app/Actions/Task/CompleteTask.php:35`, `app/Services/Notifications/DigestService.php:173`, `packages/Chat/src/Services/MyTasksService.php:130`, `app/Queries/Crm/CrmSummaryQuery.php:124`, `app/Filament/Components/Infolists/RecordOverview.php:316`, `app/Support/CustomFieldOptionPlan.php:552,571,579`. SQL readers use `whereIn(..., TaskField::doneNames())`. The one test that names the constant (`tests/Feature/Filament/RecordPageLayoutTest.php`) switches too.
- Produces: `CreateWorkspaceCustomFields::handle()` runs inside `Locales::within($workspace->owner->effectiveLocale(), ...)` (`Workspace::owner()`, `app/Models/Workspace.php:728`). Option colours map from a stable option key to colour, so a German stage keeps its colour. `OnboardSeedManager::generateFor()` reads fixtures from `fixtures/{locale}/` when that tree exists, else `fixtures/`.

- [ ] **Step 1: Write failing tests**: `it('creates built-in attributes and stages in the creator language')` (a `de` creator → stage options are the `de` lines; inject them with `addLines()` until Task 10 lands, then assert real German), `it('keeps stage colours in every language')`, `it('seeds sample notes and tasks in the creator language')`, `it('leaves existing workspaces untouched')`, `it('completes a task in a German workspace whose status reads Erledigt')` (through `CompleteTask`), `it('counts German done tasks in the digest, My Tasks and the CRM summary')`.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run.** Expect PASS.
- [ ] **Step 5: Commit** `feat(i18n): create a workspace's built-ins and samples in its creator's language`.

### Task 9: Glossary, framework strings and vendor gaps

**Files:**
- Modify: `docs/i18n.md` (glossary section; the full rewrite is Task 19)
- Create: `lang/{de,es,fr}/{validation,auth,passwords,pagination}.php`, `lang/vendor/custom-fields/{de,es,fr}/`, `lang/vendor/activity-log/{de,es}/`, `lang/vendor/flowforge/fr/`
- Test: `tests/Arch/TranslationsTest.php`

**Interfaces:**
- Produces: the glossary table, one row per term, columns `English | Deutsch | Español | Français | Never use`. Terms: workspace, member, owner, admin, company, person, people, opportunity, stage, task, note, custom attribute, record, view, board, pipeline, sign in, sign up, Rela (untranslated), MCP server (untranslated), API token, mailbox.

- [ ] **Step 0: Check candidate words against `tests/Arch/ConventionsTest.php`.** Its retired-word checks (bare `Contact`/`Deal` labels, `contacts`, `a contact`, `custom field`, `AI chat`, `your people`) scan all of `lang/` and are English rules. French "contact" and German "Deal" would trip them. Scope each of those checks to English files (`lang/en`, `lang/en.json`, each package's `lang/en`, English docs) in this task, and add a test case proving a French `contacts` passes and an English one still fails.
- [ ] **Step 1: Write the glossary.** The "Never use" column is checked by the second translation pass, not by a test (call: the words differ per language and a list test would only catch exact strings). Pick each word against Filament's own `de`, `es`, `fr` lang files so the two vocabularies agree (for example Filament's word for "record").
- [ ] **Step 2: Generate framework strings once**: `composer require --dev laravel-lang/common && php artisan lang:add de es fr` (or `lang:update`), copy only the four files per locale, then `composer remove --dev laravel-lang/common` and confirm `git diff composer.json composer.lock` is empty. Edit `validation.php` attribute names that name our records against the glossary.
- [ ] **Step 3: Publish and translate** the vendor files listed.
  Every `filament` and `filament-*` namespace the translator registers is also a gated root (`lang/vendor/<namespace>`), found through `vendor/filament/*/resources/lang`, with or without an override directory. Its English baseline is the package's own `en` merged with our override. A key Filament lacks in `de` or `fr` is added to `lang/vendor/<namespace>/<locale>/` in the same change.
- [ ] **Step 4: Run** `php artisan test --compact tests/Arch/TranslationsTest.php tests/Arch/ConventionsTest.php`. Expect the vendor roots to pass for every locale. Rows for `lang` still fail until Tasks 10-12.
- [ ] **Step 5: Commit** `feat(i18n): add the glossary, framework strings and vendor translations`.

### Tasks 10, 11, 12: Translate the app into German, Spanish, French

One task per language, same shape. Task 10 is `de`, Task 11 is `es`, Task 12 is `fr`. Each can run in parallel once Task 9 is merged.

**Files (per locale `xx`):**
- Create: `lang/xx/**` mirroring `lang/en/**`, `lang/xx.json`, `packages/ImportWizard/resources/lang/xx/**`, `packages/Cloud/lang/xx/{billing,inactivity,mail}.php` (`admin.php` is read only by the internal admin panel and stays English), `lang/xx/enums/**`, the onboarding fixtures under `packages/OnboardSeed/resources/fixtures/xx/` from Task 8
- Modify: `lang/.snapshots/xx.json` via `php artisan locale:diff xx --update-snapshot`
- Starting points: German from EnjoyJazz's PR on #1220 if it is open by then (merge its commits, keep authorship). Spanish and French are translated from scratch. srchema's fork was never offered, so it is not copied.

- [ ] **Step 1: Translate** in batches of one lang file group at a time, with the glossary and the address rule in context. Placeholders (`:name`, `:count`), pipe plurals and HTML stay intact.
- [ ] **Step 2: Second pass**: check every file against the glossary's "Never use" column, the address rule and the writing rules.
- [ ] **Step 3: Run** `php artisan test --compact tests/Arch/TranslationsTest.php --filter='xx (lang|packages/ImportWizard|lang/vendor)'` and `composer test:arch`. Expect the `xx` rows of the core, ImportWizard and vendor roots to PASS. The `xx packages/Cloud/lang` row waits for Task 17, and Cloud's `admin.php` stays English through `translationsKeptEnglish()`.
- [ ] **Step 4: Walk** sign-up, onboarding, the companies list, a record page, Rela and the profile in `xx` with agent-browser in light and dark, screenshot each into `.context/i18n/xx/`. Any English on screen is a missing key: fix it at its source and re-run Step 3.
- [ ] **Step 5: Commit** `feat(i18n): translate the app into <language>`.

---

## Part 2: The public site

### Task 13: Language-prefixed routes, the URL owner, hreflang and the switcher

**Files:**
- Create: `packages/Cloud/routes/global.php` (sitemap, `/discord`, `POST /contact`, `/llms.txt`, legacy `/docs` and `/documentation` redirects, moved out of `marketing.php` and `docs.php`), `packages/Cloud/src/Marketing/Http/Middleware/SetPublicLocale.php`, `packages/Cloud/src/Marketing/Support/LocalizedUrl.php`, `packages/Cloud/resources/views/components/layout/alternate-links.blade.php`, `packages/Cloud/resources/views/components/language-switcher.blade.php`
- Modify: `packages/Cloud/src/CloudServiceProvider.php:190-206` (register `marketing.php` and `docs.php` once unprefixed and once per non-default locale with `prefix($locale)`, `name("{$locale}.")`, `SetPublicLocale:{locale}`), `packages/Cloud/routes/marketing.php` (name the home route `home`), `packages/Cloud/resources/views/components/layout/head.blade.php:27,49`, `packages/Cloud/resources/views/layouts/guest.blade.php:13`, `packages/Cloud/resources/views/docs/shell.blade.php:12`, the marketing footer and docs shell (switcher), every `route('<public name>')` in `packages/Cloud/resources/views` and `MarketingNavigation.php` → `LocalizedUrl::route()`
- Test: `packages/Cloud/tests/Feature/Marketing/LocalizedPagesTest.php` (create), `PublicPagesTest.php`, `MarketingNavigationTest.php`, `PrimaryHostRedirectTest.php`, `tests/Smoke/RouteTest.php`

**Interfaces:**
- Produces: the unprefixed public routes carry `SetPublicLocale:en`, so English URLs render English for every visitor, whatever their cookie, header or profile says. That covers `marketing.php`, `docs.php` and the unprefixed ink blog routes (through `ink.middleware` in `CloudServiceProvider::configureBlog()`). `SetPublicLocale` also overwrites the hidden `locale` in `Context`, so jobs dispatched from a public page carry the page's language. `ProvideMarkdownResponse` caches by host and path, so an English URL must never render another language or the cache replays it to every visitor.
- Produces: `LocalizedUrl::route(string $name, array $parameters = [], ?string $locale = null): string` (current locale by default; `en` uses the unprefixed name), `LocalizedUrl::alternates(): array<string, string>` (locale → URL of the current route in that locale, plus `'x-default'` → English), `LocalizedUrl::current(string $locale): string`.
- Produces: `SetPublicLocale` applies the locale with `Locales::apply()` and queues the `Locales::COOKIE` cookie for a year, on English pages too (call: the language of the last page read is the best guess for sign-up).

- [ ] **Step 1: Write failing tests** in `LocalizedPagesTest.php`: `it('serves every public page under each prefix')` (dataset of every named public GET route × `de`, `es`, `fr`, 200), `it('keeps every English URL unchanged')` (snapshot of `route()` output for each public name), `it('serves /de and /de/ as the German home')`, `it('does not treat /developers as a German URL')`, `it('emits hreflang for all four languages plus x-default and a self canonical')`, `it('sets html lang and og:locale from the prefix')`, `it('keeps links on a German page German')` (every `href` on `/de/pricing` pointing at a public page starts with `/de`), `it('sets the locale cookie')`, `it('keeps /pricing, /help and /blog English for a German cookie, browser and signed-in user')`, `it('carries the page language into the Context of a public request')`, `it('serves the German Markdown response under /de')` (`Accept: text/markdown` on `/de/pricing` returns the German page).
- [ ] **Step 2: Run** `php artisan test --compact packages/Cloud/tests/Feature/Marketing/LocalizedPagesTest.php`. Expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the new file, the four existing ones, and `tests/Smoke/RouteTest.php`. Expect PASS. Check the switcher with agent-browser in light, dark and a 390px viewport.
- [ ] **Step 5: Commit** `feat(cloud): serve the public site under /de, /es and /fr with hreflang`.

### Task 14: Every marketing string through the lang files

**Files:**
- Modify: the 12 marketing views with literal text (start with `marketing/home/partials/hero-agent-*.blade.php`, `marketing/partials/ai-playground.blade.php`, `marketing/contact.blade.php`), `marketing/home/index.blade.php:11-37` (FAQ), `packages/Cloud/resources/data/competitor-facts.php`, `packages/Cloud/config/comparisons.php`, `packages/Cloud/src/Marketing/Enums/ConnectableAssistant.php:12-18`, `TermsOfServiceController.php`, `PrivacyPolicyController.php` and their views (one translated notice line above the English text when the locale is not `en`)
- Test: `packages/Cloud/tests/Feature/Marketing/LocalizedPagesTest.php`, `PublicPagesTest.php`, `ComparisonPagesTest.php`

**Interfaces:**
- Consumes: `packages/Cloud/lang/en.json` (Task 6), regenerated by `php artisan lang:extract`.

- [ ] **Step 1: Write failing tests**: `it('renders no catalogue English on a German page')`. Set the locale to `de` and render each marketing page. Collect every string from `packages/Cloud/lang/en.json` that has a different `de` value. Assert none appears in the HTML. The test is red now because `de.json` does not exist yet, and the next step makes it meaningful. Add `it('shows the English-governs notice on translated legal pages')`.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Wrap the literals,** move FAQ and data copy into keys, run `php artisan lang:extract`.
- [ ] **Step 4: Run** `php artisan lang:extract --check` and the three test files. The notice test PASSES. The German row waits for Task 17.
- [ ] **Step 5: Commit** `refactor(cloud): route every marketing string through the lang files`.

### Task 15: Docs and help in every language

**Files:**
- Modify: `packages/Documentation/src/Support/DocsRepository.php:80-177` (one manifest per locale; `build()` reads `{root}/{area}/...` for `en` and `{root}/{locale}/{area}/...` for the others), `packages/Documentation/src/Support/DocUrl.php` (prefix from the current locale), `packages/Documentation/src/Support/BuildSearchIndex.php` (one cache key per locale), `packages/Cloud/src/Docs/Http/Controllers/{HelpController,DocumentationController}.php`, `DocsNavigation`, `packages/Cloud/src/Marketing/Console/Commands/GenerateSitemapCommand.php:78-100`, `packages/Chat/src/Tools/SearchDocsTool.php:111-136`
- Test: `tests/Feature/Documentation/DocsRepositoryTest.php`, `tests/Feature/Documentation/HelpContentIntegrityTest.php`, `packages/Cloud/tests/Feature/Docs/HelpRoutesTest.php`, `tests/Feature/Documentation/DocsTranslationParityTest.php` (create)

**Interfaces:**
- Produces: `DocsRepository::pages(?string $locale = null)`, `find(string $path, ?string $locale = null)` (and the same optional parameter on `categories`, `findCategory`, `pagesIn`). `null` means the current app locale. The `documentation.manifest` cache key gains the locale.
- Produces: `DocUrl` methods prefix `/{locale}` when the current locale is not `en`.
- Produces: `SearchDocsTool` searches the user's locale index, and adds English results only when the localized search returns fewer than the limit.

- [ ] **Step 1: Write failing tests**: `it('loads a German page from content/de')`, `it('keeps the English tree unchanged')`, `it('builds one search index per language')`, `it('falls back to English search results for Rela')`, and in `DocsTranslationParityTest.php` `it('has every English page in {locale} with title and description')` (dataset `de`, `es`, `fr`; fails until Task 17). Add `it('applies every HelpContentIntegrityTest rule to each language tree')` by turning the existing checks into a dataset over the four trees.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the four files. Expect PASS except the parity rows.
- [ ] **Step 5: Commit** `feat(docs): load help and developer docs per language`.

### Task 16: The blog in every language, and the sitemap

**Files:**
- Modify: `composer.json` (`"relaticle/ink": "^2.6"`), `composer.lock`, `packages/Cloud/src/CloudServiceProvider.php:171-187` (`ink.locales` = `config('app.available_locales')`), `packages/Cloud/resources/views/blog/*.blade.php` and `components/blog/article.blade.php` (alternates from `Post::translations()`, links via `Post::getUrl()`), the marketing navigation blog link (hidden for a locale with no published post), `GenerateSitemapCommand.php:31-42` (marketing alternates for every page)
- Test: `packages/Cloud/tests/Feature/Blog/BlogPagesTest.php`, `packages/Cloud/tests/Feature/Marketing/GenerateSitemapCommandTest.php`

**Interfaces:**
- Consumes: ink 2.6 (Task 0), `LocalizedUrl::alternates()` (Task 13).

- [ ] **Step 1: Write failing tests**: `it('shows a German post at /de/blog/{slug} with its English sibling as an alternate')`, `it('links a German page to the English post when no German version exists')`, `it('hides the blog link on a language with no published post')`, `it('lists every public page in every language with xhtml:link alternates')`.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Bump ink, implement.**
- [ ] **Step 4: Run** both files and `packages/Cloud/tests/Feature/Blog`. Expect PASS.
- [ ] **Step 5: Commit** `feat(blog): publish posts per language on ink 2.6`.

### Tasks 17, 18: Translate the marketing site, help and developer docs

Task 17 does marketing (`packages/Cloud/lang/{de,es,fr}.json`, plus any Cloud PHP lang groups the marketing pages read). Task 18 does docs (`packages/Documentation/resources/content/{de,es,fr}/{help,docs}/**`). Each splits by language across parallel workers if useful.

- [ ] **Step 1: Translate** with the glossary and address rule in context. Docs keep the same paths, front matter keys, headings structure, link targets (rewritten to the language's URLs through `DocUrl` conventions) and image paths. Values in front matter carry no bare `: `.
- [ ] **Step 2: Second pass** against the glossary and the writing rules.
- [ ] **Step 3: Run** `php artisan test --compact packages/Cloud/tests/Feature/Marketing/LocalizedPagesTest.php tests/Feature/Documentation/DocsTranslationParityTest.php tests/Feature/Documentation/HelpContentIntegrityTest.php` and `composer test:arch`. Expect PASS on every language row, including `php artisan test --compact tests/Arch/TranslationsTest.php --filter='xx packages/Cloud/lang'` for each language.
- [ ] **Step 4: Walk** the homepage, pricing, one compare page, help index, one help article and one developer guide in each language with agent-browser, light and dark, plus a 390px viewport for the homepage.
- [ ] **Step 5: Commit** `feat(i18n): translate the marketing site` and `feat(i18n): translate the help and developer docs`.

### Task 19: Docs, final checks and the PR

**Files:**
- Modify: `docs/i18n.md` (rewrite: four languages ship in main; how to add a string and run `lang:extract`; the glossary; how a native speaker fixes a translation with a PR; how to add a fifth language), `.ai/guidelines/relaticle/*.md` only if a rule changes (then `php artisan boost:update` and copy `AGENTS.md` to `GEMINI.md`)

- [ ] **Step 1: Rewrite** `docs/i18n.md`.
- [ ] **Step 2: Run the pre-push loop**: `vendor/bin/rector --dry-run`, `vendor/bin/phpstan analyse`, `composer test:lint`, `composer test:arch`, `composer test:affected` (expect it to refuse past 60 files; then run the touched test files by directory with `--parallel --processes=4`).
- [ ] **Step 3: Run the browser tests** for the surfaces changed in WebKit: `php artisan test --compact tests/Browser/Chat/LoadingShimmerTest.php <new browser tests> --browser safari`.
- [ ] **Step 4: Push and open the PR** with the full body per `CLAUDE.md`. Name the launch step: after deploy, translate the 37 published posts through the blog MCP server with `translation_of`, in all three languages. Watch the `Tests` workflow as a background task.
- [ ] **Step 5: After merge and deploy**, run the blog translations, then request a re-crawl of the sitemap in Search Console.
