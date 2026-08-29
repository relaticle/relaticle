# Test Organization, Factories, Datasets, and Pest Rector Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reclassify root tests, replace direct Eloquent fixture creation, consolidate one dataset, and apply reviewed Pest coding rules.

**Architecture:** Existing suites retain ownership. Model factories centralize valid defaults. A separate Rector configuration changes test syntax only.

**Tech Stack:** PHP 8.5, Laravel 13, Pest 5.1.1, Eloquent factories, Rector 2, Pest Rector 5.

**Spec:** `docs/superpowers/specs/2026-08-26-test-organization-factories-and-rector-design.md`

**Prerequisite:** Complete `2026-08-26-test-helper-and-assertion-hygiene.md` first.

## Global constraints

- Keep the five existing suite roots.
- Do not create `tests/Unit`.
- Preserve every moved test name and assertion.
- Convert only reviewed Eloquent `create` calls.
- Keep non-Eloquent and PHPStan-fixture calls unchanged.
- Review every automated assertion rewrite.
- Do not change application behavior.
- Run targeted development tests with `--no-tia`.
- Do not merge, push, or tag.

## Task 1: Capture the pre-change test inventory

**Files:**

- Create local evidence only under `.context`

- [ ] Record the complete Default test list.

```bash
vendor/bin/pest --list-tests --no-tia > .context/pr3-tests-before.txt
```

- [ ] Record root-level test files.

```bash
find tests/Feature tests/Browser -maxdepth 1 -name '*Test.php' -print | sort > .context/pr3-root-tests-before.txt
```

Expected: 21 files appear.

- [ ] Record static create calls.

```bash
rg -n '\b[A-Za-z_][A-Za-z0-9_]*::create\(' tests > .context/pr3-static-create-before.txt
```

Expected: 94 static calls. One reviewed dynamic model call brings the total to 95.

Keep `.context` evidence uncommitted.

## Task 2: Reclassify root-level tests

**Files:**

- Move 21 files listed below

- [ ] Create missing destination directories.

```bash
mkdir -p tests/Feature/Public tests/Feature/Filament/App/Pages \
    tests/Feature/Support tests/Feature/Auth tests/Feature/SystemAdmin \
    tests/Feature/Documentation tests/Feature/Filament tests/Feature/Rules \
    tests/Browser/CRM tests/Browser/Smoke
```

- [ ] Move each file with `git mv`.

```text
tests/Feature/ComparisonPagesTest.php -> tests/Feature/Public/ComparisonPagesTest.php
tests/Feature/ContactFormTest.php -> tests/Feature/Public/ContactFormTest.php
tests/Feature/DashboardGreetingTest.php -> tests/Feature/Filament/App/Pages/DashboardGreetingTest.php
tests/Feature/DatabaseTimezoneTest.php -> tests/Feature/Support/DatabaseTimezoneTest.php
tests/Feature/EmailVerificationConfigTest.php -> tests/Feature/Auth/EmailVerificationConfigTest.php
tests/Feature/FeatureFlagsTest.php -> tests/Feature/Support/FeatureFlagsTest.php
tests/Feature/HorizonGateTest.php -> tests/Feature/SystemAdmin/HorizonGateTest.php
tests/Feature/LocaleServiceProviderTest.php -> tests/Feature/Support/LocaleServiceProviderTest.php
tests/Feature/MarkdownResponseTest.php -> tests/Feature/Documentation/MarkdownResponseTest.php
tests/Feature/MarketingNavigationTest.php -> tests/Feature/Public/MarketingNavigationTest.php
tests/Feature/MarketingRedirectsTest.php -> tests/Feature/Documentation/MarketingRedirectsTest.php
tests/Feature/PanelNoindexTest.php -> tests/Feature/Filament/PanelNoindexTest.php
tests/Feature/PressPageTest.php -> tests/Feature/Public/PressPageTest.php
tests/Feature/PricingPageTest.php -> tests/Feature/Public/PricingPageTest.php
tests/Feature/ProductPagesTest.php -> tests/Feature/Public/ProductPagesTest.php
tests/Feature/SupportMenuTest.php -> tests/Feature/Filament/SupportMenuTest.php
tests/Feature/UserTimezoneDisplayTest.php -> tests/Feature/Filament/UserTimezoneDisplayTest.php
tests/Feature/ValidTeamSlugTest.php -> tests/Feature/Rules/ValidTeamSlugTest.php
tests/Browser/I18nCompanyResourceTest.php -> tests/Browser/CRM/I18nCompanyResourceTest.php
tests/Browser/PageHeadingTest.php -> tests/Browser/CRM/PageHeadingTest.php
tests/Browser/SmokeBrowserTest.php -> tests/Browser/Smoke/SmokeBrowserTest.php
```

- [ ] Confirm no root-level test file remains.

```bash
find tests/Feature tests/Browser -maxdepth 1 -name '*Test.php' -print
```

Expected: no output.

- [ ] Capture the new test list and compare it.

```bash
vendor/bin/pest --list-tests --no-tia > .context/pr3-tests-after-moves.txt
sed -E 's/^.*::__pest_evaluable_//' .context/pr3-tests-before.txt | sort \
    > .context/pr3-test-descriptions-before.txt
sed -E 's/^.*::__pest_evaluable_//' .context/pr3-tests-after-moves.txt | sort \
    > .context/pr3-test-descriptions-after-moves.txt
diff -u .context/pr3-test-descriptions-before.txt \
    .context/pr3-test-descriptions-after-moves.txt
```

Expected: paths may change in display output. Test descriptions and total count must match.

- [ ] Run all moved Feature files by their destination directories.

```bash
php artisan test --compact tests/Feature/Public tests/Feature/Support \
    tests/Feature/Auth/EmailVerificationConfigTest.php \
    tests/Feature/SystemAdmin/HorizonGateTest.php \
    tests/Feature/Documentation tests/Feature/Filament \
    tests/Feature/Rules/ValidTeamSlugTest.php --no-tia
```

Expected: all moved Feature tests pass.

- [ ] Commit path changes.

```bash
git add tests/Feature tests/Browser
git commit -m "test: organize root tests by domain"
```

## Task 3: Add missing model factories

**Files:**

- Create: `database/factories/CustomFieldSectionFactory.php`
- Create: `database/factories/CustomFieldValueFactory.php`
- Create: `database/factories/ImportFactory.php`
- Modify: `app/Models/CustomFieldSection.php`
- Modify: `app/Models/CustomFieldValue.php`
- Modify: `packages/ImportWizard/src/Models/Import.php`
- Test through existing API and ImportWizard files

- [ ] Change one setup call per model to `Model::factory()->create([...])`.

Use these existing consumers:

- `tests/Feature/Api/V1/CustomFieldsApiTest.php`
- `tests/Feature/ImportWizard/Jobs/ResolveMatchesJobTest.php`

- [ ] Run the two tests and confirm factory discovery fails.

```bash
php artisan test --compact tests/Feature/Api/V1/CustomFieldsApiTest.php \
    tests/Feature/ImportWizard/Jobs/ResolveMatchesJobTest.php --no-tia
```

Expected: missing factory support fails before scaffolding.

- [ ] Scaffold factories with Artisan.

```bash
php artisan make:factory CustomFieldSectionFactory --model=App\\Models\\CustomFieldSection --no-interaction
php artisan make:factory CustomFieldValueFactory --model=App\\Models\\CustomFieldValue --no-interaction
php artisan make:factory ImportFactory --model=Relaticle\\ImportWizard\\Models\\Import --no-interaction
```

- [ ] Add `HasFactory` to each model.

Use the correct generic factory PHPDoc above each trait use.

```php
/** @use HasFactory<CustomFieldSectionFactory> */
use HasFactory;
```

Apply the matching factory type to each model.

- [ ] Implement valid minimal defaults.

`CustomFieldSectionFactory` must provide tenant, entity type, unique code, name, type, order, active, and system-defined values.

`CustomFieldValueFactory` must create a tenant-consistent field and entity. It must populate one typed value column.

`ImportFactory` must provide team, user, entity type, file name, status, totals, headers, mappings, and zeroed counters.

Use current enums and model factories. Do not use database-clock defaults.

- [ ] Add named factory states only when two or more consumers share the same meaningful setup.

Keep one-off values inside the calling test.

- [ ] Run the two consumer files again.

```bash
php artisan test --compact tests/Feature/Api/V1/CustomFieldsApiTest.php \
    tests/Feature/ImportWizard/Jobs/ResolveMatchesJobTest.php --no-tia
```

Expected: both files pass.

## Task 4: Migrate reviewed Eloquent creation calls

**Files:**

- Modify: `tests/Feature/Api/V1/CompaniesApiTest.php`
- Modify: `tests/Feature/Api/V1/CustomFieldsApiTest.php`
- Modify: `tests/Feature/Api/V1/OpportunitiesApiTest.php`
- Modify: `tests/Feature/ImportWizard/Commands/CleanupImportsCommandTest.php`
- Modify: all seven reviewed ImportWizard Job and Livewire consumers
- Modify: `tests/Feature/Mcp/McpToolFeaturesTest.php`
- Modify: `tests/Feature/Mcp/SchemaResourcesTest.php`
- Modify: three reviewed SystemAdmin test files

- [ ] Replace all 38 `CustomField::create` calls with `CustomField::factory()->create`.

- [ ] Replace all 13 `CustomFieldSection::create` calls with factory calls.

- [ ] Replace all 10 `Import::create` calls with factory calls.

- [ ] Replace all six `CustomFieldValue::create` calls with factory calls.

- [ ] Replace both `Category::create` calls with the package factory.

Preserve every explicit attribute array. Remove attributes only when the new default is identical and irrelevant to the test.

- [ ] Keep these 26 calls unchanged.

```text
10 ImportStore calls
7 RelationshipMatch value-object calls
2 Request calls
2 EncryptedTime calls
2 PHPStan fixture Company calls
1 Passport dynamic client-model call
1 HandlerStack call
1 Date call
```

- [ ] Re-run the static-call inventory.

```bash
rg -n '\b[A-Za-z_][A-Za-z0-9_]*::create\(' tests > .context/pr3-static-create-after.txt
```

Expected: 25 static calls remain. The reviewed dynamic model call brings the total to 26. No target Eloquent model appears.

- [ ] Run all factory consumers.

```bash
php artisan test --compact tests/Feature/Api/V1 \
    tests/Feature/ImportWizard tests/Feature/Mcp \
    tests/Feature/SystemAdmin --no-tia
```

Expected: all tests pass.

- [ ] Commit factory support and migration.

```bash
git add app/Models/CustomFieldSection.php app/Models/CustomFieldValue.php \
    packages/ImportWizard/src/Models/Import.php database/factories tests/Feature
git commit -m "test: build model fixtures through factories"
```

## Task 5: Consolidate the documentation dataset

**Files:**

- Modify: `tests/Feature/Documentation/HelpRoutesTest.php`

- [ ] Add one named dataset near the imports.

```php
dataset('documentation shell pages', [
    '/help',
    '/help/getting-started',
    '/help/getting-started/create-your-first-company',
    '/developers',
    '/developers/mcp',
]);
```

- [ ] Replace both repeated inline arrays with the dataset name.

```php
->with('documentation shell pages');
```

- [ ] Run the file.

```bash
php artisan test --compact tests/Feature/Documentation/HelpRoutesTest.php --no-tia
```

Expected: all tests pass with the same expanded case count.

- [ ] Commit the dataset change.

```bash
git add tests/Feature/Documentation/HelpRoutesTest.php
git commit -m "test: share documentation page cases"
```

## Task 6: Add the reviewed Pest Rector configuration

**Files:**

- Modify: `composer.json`
- Modify: `composer.lock`
- Create: `rector-tests.php`

- [ ] Preview the dependency update.

```bash
composer require --dev pestphp/pest-plugin-rector:^5.0 --with-dependencies --dry-run
```

Expected additions:

- `pestphp/pest-plugin-rector` 5.0.x
- `symplify/rule-doc-generator-contracts` 11.2.x

Stop if Composer changes unrelated installed packages.

- [ ] Apply the dependency update and run the audit.

```bash
composer require --dev pestphp/pest-plugin-rector:^5.0 --with-dependencies
composer audit
```

Expected: no security advisories.

- [ ] Create `rector-tests.php`.

```php
<?php

declare(strict_types=1);

use Pest\Rector\Rules\ChainExpectCallsRector;
use Pest\Rector\Rules\EnsureTypeChecksFirstRector;
use Pest\Rector\Rules\SimplifyToLiteralBooleanRector;
use Pest\Rector\Rules\UseToBeEmptyRector;
use Pest\Rector\Rules\UseToBeInRector;
use Pest\Rector\Rules\UseToContainRector;
use Pest\Rector\Rules\UseToMatchRector;
use Pest\Rector\Rules\UseToThrowRector;
use Pest\Rector\Set\PestSetList;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withCache(cacheDirectory: __DIR__.'/.cache/rector-tests')
    ->withPaths([__DIR__.'/tests'])
    ->withSkip([
        ChainExpectCallsRector::class,
        EnsureTypeChecksFirstRector::class,
        SimplifyToLiteralBooleanRector::class,
        UseToBeEmptyRector::class,
        UseToBeInRector::class,
        UseToThrowRector::class,
        UseToContainRector::class => [
            __DIR__.'/tests/Feature/Migrations/UlidMigrationTest.php',
        ],
        UseToMatchRector::class => [
            __DIR__.'/tests/Browser/Chat/TranscriptShapeTest.php',
        ],
    ])
    ->withSets([
        PestSetList::CODING_STYLE,
    ]);
```

- [ ] Run a dry-run and save the proposed diff.

```bash
vendor/bin/rector process --config=rector-tests.php --dry-run > .context/pr3-rector-tests-dry-run.txt
```

Historical execution result: 45 test files were initially proposed before the
final review added the two strict-assertion exclusions.

- [ ] Review every proposed hunk before applying.

Reject any hunk that changes evaluated values, exception scope, diagnostics, or assertion order.

The review must reject these five proposals through configuration:

- `EnsureTypeChecksFirstRector` in `GitHubServiceTest.php` changes assertion order.
- `SimplifyToLiteralBooleanRector` weakens strict empty-array assertions.
- `UseToBeEmptyRector` replaces exact zero-count assertions with generic emptiness checks.
- `UseToMatchRector` in `TranscriptShapeTest.php` removes a required regex capture.
- `UseToContainRector` in `UlidMigrationTest.php` removes custom failure diagnostics.

Historical execution result: 42 test files remained before the final review.
The final clean-tree requirement is zero proposed changes.

- [ ] Apply the reviewed configuration.

```bash
vendor/bin/rector process --config=rector-tests.php
vendor/bin/pint --dirty --format agent
```

- [ ] Update Composer automation.

`test:refactor` must dry-run `rector.php` and `rector-tests.php`.

`lint` must apply `rector.php`, then `rector-tests.php`, then Pint.

Use Composer script arrays. Keep commands explicit.

- [ ] Run both dry-runs.

```bash
composer test:refactor
```

Expected: neither configuration proposes changes.

- [ ] Run the complete Default test suite.

```bash
composer test:pest:full
```

Expected: all tests pass. Test descriptions and total count match the baseline.

- [ ] Commit the Rector work.

```bash
git add composer.json composer.lock rector-tests.php tests
git commit -m "test: apply reviewed Pest coding rules"
```

## Task 7: Final inventory and verification

- [ ] Compare test inventories.

```bash
vendor/bin/pest --list-tests --no-tia > .context/pr3-tests-final.txt
```

Compare descriptions and counts against `.context/pr3-tests-before.txt`.

Normalize the generated class prefix as shown in Task 2 before comparing descriptions.

Expected: names and counts match. Only file paths differ.

- [ ] Confirm root directories remain empty of test files.

```bash
find tests/Feature tests/Browser -maxdepth 1 -name '*Test.php' -print
```

Expected: no output.

- [ ] Confirm reviewed static create count.

```bash
rg -n '\b[A-Za-z_][A-Za-z0-9_]*::create\(' tests
```

Expected: 26 reviewed non-Eloquent or fixture calls.

- [ ] Run checks in project order.

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
composer test:pest:full
composer test:lint
```

Expected: all checks pass. Type coverage remains 100 percent.

- [ ] Refresh shard timings only if the full run changed materially.

```bash
composer test:update-shards
```

If run, commit `tests/.pest/shards.json` with the timing changes.

- [ ] Review the final diff and the 27-item direct-internal audit.

Do not add an entrypoint gate. Record that audit as deferred in the pull request draft.

- [ ] Prepare the pull request draft. Do not publish it without explicit approval.
