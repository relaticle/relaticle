# Test Organization, Factories, Datasets, and Pest Rector

Date: 2026-08-26
Status: approved
Sequence: PR3 of 4
Prerequisite: test helper and assertion hygiene

## 1. Goal

Improve test discovery, fixture intent, and Pest style without changing suite boundaries or application behavior.

## 2. Suite organization

Keep the five existing test suites. Do not add `tests/Unit`.

Move root-level files into existing domain folders:

| Current file | Destination |
| --- | --- |
| `tests/Feature/ComparisonPagesTest.php` | `tests/Feature/Public/ComparisonPagesTest.php` |
| `tests/Feature/ContactFormTest.php` | `tests/Feature/Public/ContactFormTest.php` |
| `tests/Feature/DashboardGreetingTest.php` | `tests/Feature/Filament/App/Pages/DashboardGreetingTest.php` |
| `tests/Feature/DatabaseTimezoneTest.php` | `tests/Feature/Support/DatabaseTimezoneTest.php` |
| `tests/Feature/EmailVerificationConfigTest.php` | `tests/Feature/Auth/EmailVerificationConfigTest.php` |
| `tests/Feature/FeatureFlagsTest.php` | `tests/Feature/Support/FeatureFlagsTest.php` |
| `tests/Feature/HorizonGateTest.php` | `tests/Feature/SystemAdmin/HorizonGateTest.php` |
| `tests/Feature/LocaleServiceProviderTest.php` | `tests/Feature/Support/LocaleServiceProviderTest.php` |
| `tests/Feature/MarkdownResponseTest.php` | `tests/Feature/Documentation/MarkdownResponseTest.php` |
| `tests/Feature/MarketingNavigationTest.php` | `tests/Feature/Public/MarketingNavigationTest.php` |
| `tests/Feature/MarketingRedirectsTest.php` | `tests/Feature/Documentation/MarketingRedirectsTest.php` |
| `tests/Feature/PanelNoindexTest.php` | `tests/Feature/Filament/PanelNoindexTest.php` |
| `tests/Feature/PressPageTest.php` | `tests/Feature/Public/PressPageTest.php` |
| `tests/Feature/PricingPageTest.php` | `tests/Feature/Public/PricingPageTest.php` |
| `tests/Feature/ProductPagesTest.php` | `tests/Feature/Public/ProductPagesTest.php` |
| `tests/Feature/SupportMenuTest.php` | `tests/Feature/Filament/SupportMenuTest.php` |
| `tests/Feature/UserTimezoneDisplayTest.php` | `tests/Feature/Filament/UserTimezoneDisplayTest.php` |
| `tests/Feature/ValidTeamSlugTest.php` | `tests/Feature/Rules/ValidTeamSlugTest.php` |
| `tests/Browser/I18nCompanyResourceTest.php` | `tests/Browser/CRM/I18nCompanyResourceTest.php` |
| `tests/Browser/PageHeadingTest.php` | `tests/Browser/CRM/PageHeadingTest.php` |
| `tests/Browser/SmokeBrowserTest.php` | `tests/Browser/Smoke/SmokeBrowserTest.php` |

These moves change paths only. Test names and assertions stay unchanged.

## 3. Direct-internal test audit

The MaxForms entrypoint heuristic identifies 27 possible direct-internal Feature tests.

Do not enforce that heuristic in this pull request.

Relaticle deliberately tests several services through integration-shaped Feature tests.

Moving them would conflict with the repository's no-Unit-suite rule.

Behavioral rewrites need separate domain decisions. Record the audit outcome in the pull request description.

## 4. Factory migration

The current suite contains 95 reviewed `create` calls: 94 static calls and one nested dynamic model call.

Convert 69 Eloquent model calls to factories:

| Model | Calls |
| --- | ---: |
| `CustomField` | 38 |
| `CustomFieldSection` | 13 |
| `Import` | 10 |
| `CustomFieldValue` | 6 |
| `Category` | 2 |

Keep 26 non-Eloquent, framework, value-object, and PHPStan-fixture calls.

Create these factories:

- `database/factories/CustomFieldSectionFactory.php`
- `database/factories/CustomFieldValueFactory.php`
- `database/factories/ImportFactory.php`

Reuse the existing `CustomFieldFactory` and Category factory.

Add `HasFactory` with typed factory PHPDoc to these models:

- `App\Models\CustomFieldSection`
- `App\Models\CustomFieldValue`
- `Relaticle\ImportWizard\Models\Import`

Factory defaults must create valid minimal records. Relationship states must express special setup.

Do not hide important test intent behind broad factory callbacks.

## 5. Dataset consolidation

Only one repeated inline dataset merits extraction.

Create the named dataset `documentation shell pages` in `HelpRoutesTest.php`.

Use it for both repeated five-path checks.

Do not create named datasets used once.

## 6. Pest Rector

Add `pestphp/pest-plugin-rector:^5.0` as a development dependency.

The narrow Composer update should add only:

- `pestphp/pest-plugin-rector`
- `symplify/rule-doc-generator-contracts`

Create `rector-tests.php` with `PestSetList::CODING_STYLE`.

Skip these rules:

- `ChainExpectCallsRector`
- `EnsureTypeChecksFirstRector`
- `UseToThrowRector`
- `UseToBeInRector`
- `SimplifyToLiteralBooleanRector`
- `UseToBeEmptyRector`

The first reduces assertion locality across many files.

The second changes assertion order.

The third changes an existing try-catch test's semantics.

The fourth removes custom failure diagnostics.

The fifth converts strict empty-array assertions to generic emptiness checks.

The sixth replaces exact zero-count assertions with generic emptiness checks.

Skip `UseToMatchRector` for `TranscriptShapeTest.php`. Its replacement drops the
regex capture that the next assertion reads.

Skip `UseToContainRector` for `UlidMigrationTest.php`. Its replacements drop the
table and column names from custom failure diagnostics.

Review every applied Rector change. Do not accept behavioral assertion changes.

Update `test:refactor` to dry-run both Rector configurations.

Update `lint` to apply both configurations before Pint.

## 7. Verification

Capture the test list before file moves. Capture it again after all changes.

The test count and test names must match.

Run targeted tests for each moved domain and every factory consumer.

Run Rector dry-runs for production and test configurations.

Run the complete non-Browser suite after the mechanical changes.

Refresh shard timings only if measured timings change materially.

## 8. Acceptance criteria

- No test file remains directly under `tests/Feature` or `tests/Browser`.
- The five existing suite roots remain unchanged.
- All 69 Eloquent creation calls use factories.
- The 26 reviewed non-Eloquent calls remain.
- Factory-backed tests preserve their setup and assertions.
- The duplicated documentation paths use one named dataset.
- Pest Rector applies only the reviewed coding-style rules.
- Test names and counts remain unchanged.
- Application files outside model factory support remain unchanged.

## 9. Non-goals

- No direct-internal test gate.
- No new suite.
- No application behavior changes.
- No slow-test splitting.
- No broad dataset extraction.
