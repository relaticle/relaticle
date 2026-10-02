# CRM Filter Language Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `sdd-lean` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One filter language (native fields, custom fields, relations, `$and`/`$or`/`$not`) that answers a list question identically on the REST API, MCP and chat, with canonical phone and domain values underneath.

**Architecture:** Spatie query-builder 7 stays the parser and registry. A per-entity registry (`EntityFilters`, keyed by `CrmEntity`) turns definitions into `AllowedFilter`s; three small filter classes (native, relation, logic) plus the existing custom field filter compile the tree with Laravel's builder. Value normalization moves into the custom-fields package's existing `BaseFieldType::setValue()` hook, wired into the one write funnel every path already calls.

**Tech Stack:** PHP 8.5, Laravel 12, PostgreSQL, spatie/laravel-query-builder 7.3.5, relaticle/custom-fields 3.11 to 3.12, laravel/mcp, laravel/ai, Pest 4, Scribe, libphonenumber (giggsey) and propaganistas/laravel-phone.

**Spec:** `docs/superpowers/specs/2026-10-02-crm-filter-language-design.md`

## Global Constraints

- PostgreSQL only. No driver checks or compatibility layers.
- Every PHP file starts with `declare(strict_types=1);`. Classes are `final` (`final readonly` where possible). All parameters and returns typed; type coverage stays 100%.
- Actions expose only `execute()` (`tests/Arch/ConventionsTest.php`).
- `tests/Arch/ArchTest.php`: every `App` class is `final`, no abstract classes, and `App\Support` classes are `readonly` and extend nothing. A class that must extend a vendor class (`TreeAllowedFilter extends AllowedFilter`) gets an entry with a one-line reason in both the "avoid mutation" and "avoid inheritance" ignore lists. Share code between filter classes with a trait, never a base class.
- No public method outside a model, enum or `Scope` takes a query builder unless it implements an interface or overrides a parent (`ConventionsTest`, `hasPrototype()`). Builder code lives in Spatie `Filter::__invoke()`, `AllowedFilter::applyTo()`/`filter()` overrides, and private or protected helpers.
- No comments narrating the diff; no comments in tests; docblocks carry types only.
- Never an em-dash (U+2014) in code, copy, commits or docs. No competitor names in any committed file.
- User-facing strings go through `__()`; filter messages live under `validation.filter.*` and `validation.custom_field.*` in `lang/en/validation.php`.
- Never name the mutable `Carbon` class; dates are `CarbonImmutable` via `Date`/`now()`.
- Migrations have `up()` only. A data backfill that needs app code is a command that reports by default and writes on `--force`, queued from a migration with `Artisan::queue(...)->onQueue('imports')->afterCommit()`.
- Limits: 20 conditions, logic depth 3, relation hops 2, 100 values per list (`CustomFieldFilterSchema::MAX_LIST_VALUES`).
- Grammar: `$` marks a keyword (operator or logic); bare keys are fields, relations, `custom_fields` or sub-fields. No shorthand.
- Before each commit: `vendor/bin/pint --dirty --format agent`, `vendor/bin/rector --dry-run`, `vendor/bin/phpstan analyse`, targeted `php artisan test --compact --filter=...`. No new PHPStan ignores.
- Package work happens in the `relaticle/custom-fields` repo, never in `vendor/`. Do not touch the `~/Herd/custom-fields` checkout's current branch; use a fresh worktree from `3.x`.
- Git: conventional commits, lowercase subject under 72 chars, no AI attribution. `docs/` is gitignored here: force-add plan/spec files only.

## Review Focus

1. A company custom field coded `name` (production has 4 such fields): `filter.name` must hit the native column and `filter.custom_fields.name` the custom field, never each other. Pinned in Task 10.
2. A GET text value of `true` (`filter[custom_fields][motto][$eq]=true`): Spatie turns it into a boolean first; the filter must still compare the text `true`. Pinned in Task 9.
3. A soft-deleted related company must not satisfy `company.custom_fields.icp`, and a trashed company id in `company.$in` matches nothing. Pinned in Task 11.
4. `$not` over a to-many relation (`{"$not": {"people": {...}}}`) must return companies that have no people at all. Pinned in Task 11.
5. A member id from another workspace in `assignees.$in` returns an empty list, not an error or a leak. Pinned in Task 10.

---

## Part A: custom-fields package (release v3.12.0)

Work in a worktree of `relaticle/custom-fields` on a branch from `3.x`:

```bash
git -C ~/Herd/custom-fields fetch origin
git -C ~/Herd/custom-fields worktree add ../custom-fields-normalize -b feat/normalize-on-write origin/3.x
cd ~/Herd/custom-fields-normalize && composer install
```

Package gates: `vendor/bin/pest --filter=<name>`, `composer test:types`, `composer test:lint`, `composer test:type-coverage`.

### Task 1: Normalize values on every write path

**Files:**
- Modify: `src/FieldTypeSystem/BaseFieldType.php`
- Modify: `src/Support/SafeValueConverter.php`
- Modify: `src/Models/CustomFieldValue.php:122-133`
- Modify: `src/Filament/Integration/Components/Forms/LinkComponent.php:31-37`
- Modify: `src/Rules/UniqueCustomFieldValue.php:33-39`
- Test: `tests/Feature/Models/CustomFieldValueNormalizationTest.php` (create)

**Interfaces:**
- Produces: `BaseFieldType::normalize(string $value, CustomField $customField): string` (defaults to `setValue($value)`); `SafeValueConverter::toDbSafe(mixed $value, string $fieldType, ?CustomField $customField = null): mixed` (normalizes string items when a field is given, drops duplicates created by normalization).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Models\CustomField;
use Relaticle\CustomFields\Models\CustomFieldSection;
use Relaticle\CustomFields\Models\CustomFieldValue;
use Relaticle\CustomFields\Support\SafeValueConverter;
use Relaticle\CustomFields\Tests\Fixtures\Models\Post;
use Relaticle\CustomFields\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());

    $section = CustomFieldSection::factory()->forEntityType(Post::class)->create(['active' => true]);

    $this->linkField = CustomField::factory()->create([
        'custom_field_section_id' => $section->getKey(),
        'entity_type' => Post::class,
        'code' => 'website',
        'name' => 'Website',
        'type' => 'link',
        'settings' => new CustomFieldSettingsData(allow_multiple: true, max_values: 5),
    ]);
});

it('strips the scheme from a link saved outside the panel form', function (): void {
    $post = Post::factory()->create();

    $post->saveCustomFieldValue($this->linkField, ['https://example.com/pricing']);

    $stored = CustomFieldValue::query()->where('entity_id', $post->getKey())->where('custom_field_id', $this->linkField->getKey())->firstOrFail();

    expect(collect($stored->json_value)->all())->toBe(['example.com/pricing']);
});

it('collapses values that normalize to the same link', function (): void {
    expect(SafeValueConverter::toDbSafe(['https://example.com', 'http://example.com', 'example.com'], 'link', $this->linkField))
        ->toBe(['example.com']);
});

it('leaves values untouched when no field is given', function (): void {
    expect(SafeValueConverter::toDbSafe(['https://example.com'], 'link'))->toBe(['https://example.com']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Models/CustomFieldValueNormalizationTest.php`
Expected: FAIL. The first test stores `https://example.com/pricing`; the second returns all three values.

- [ ] **Step 3: Implement**

`src/FieldTypeSystem/BaseFieldType.php`, add after `setValue()` (import `Relaticle\CustomFields\Models\CustomField`):

```php
    /**
     * Normalize a value for one field; a field setting may choose the form.
     */
    public function normalize(string $value, CustomField $customField): string
    {
        return $this->setValue($value);
    }
```

`src/Support/SafeValueConverter.php`, replace `toDbSafe()` (imports: `Relaticle\CustomFields\FieldTypeSystem\BaseFieldType`, `Relaticle\CustomFields\Models\CustomField`):

```php
    public static function toDbSafe(mixed $value, string $fieldType, ?CustomField $customField = null): mixed
    {
        $fieldTypeData = CustomFieldsType::getFieldType($fieldType);

        if ($fieldTypeData === null) {
            return $value;
        }

        $converted = self::convertByDataType($value, $fieldTypeData->dataType);
        $definition = CustomFieldsType::getFieldTypeInstance($fieldType);

        if (! $customField instanceof CustomField || ! $definition instanceof BaseFieldType) {
            return $converted;
        }

        if (is_array($converted)) {
            return array_values(array_unique(array_map(
                fn (mixed $item): mixed => is_string($item) && $item !== '' ? $definition->normalize($item, $customField) : $item,
                $converted,
            ), SORT_REGULAR));
        }

        return is_string($converted) && $converted !== '' ? $definition->normalize($converted, $customField) : $converted;
    }
```

`src/Models/CustomFieldValue.php`, in `setValue()` pass the field:

```php
        $safeValue = SafeValueConverter::toDbSafe(
            $value,
            $this->customField->type,
            $this->customField,
        );
```

`src/Filament/Integration/Components/Forms/LinkComponent.php`, the dehydrate map:

```php
                ->map(fn (mixed $v): string => $fieldType instanceof BaseFieldType
                    ? $fieldType->normalize(trim((string) $v), $customField)
                    : trim((string) $v))
```

`src/Rules/UniqueCustomFieldValue.php`, the candidate map:

```php
            ->mapWithKeys(fn (mixed $v): array => [
                (string) $v => $fieldType instanceof BaseFieldType ? $fieldType->normalize((string) $v, $this->customField) : (string) $v,
            ]);
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Feature/Models/CustomFieldValueNormalizationTest.php tests/Feature/Rules tests/Feature/Filament`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src tests/Feature/Models/CustomFieldValueNormalizationTest.php
git commit -m "feat: normalize custom field values on every write path"
```

### Task 2: Store phones as E.164 and keep extensions

**Files:**
- Modify: `src/Services/Phone/CountryPhoneService.php`
- Modify: `src/FieldTypeSystem/Definitions/PhoneFieldType.php`
- Test: `tests/Feature/Services/CountryPhoneServiceTest.php`, `tests/Feature/Models/CustomFieldValueNormalizationTest.php`

**Interfaces:**
- Produces: `CountryPhoneService::normalize(string $value): string` returning `+14155550100` or `+14155550100;ext=12`, or the trimmed input when it cannot parse. `PhoneFieldType::setValue()` calls it.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Services/CountryPhoneServiceTest.php`:

```php
it('normalizes an international number to E.164', function (string $input, string $expected): void {
    expect($this->service->normalize($input))->toBe($expected);
})->with([
    'spaces and dashes' => ['+1 415-555-0100', '+14155550100'],
    'parentheses' => ['+1 (415) 555-0100', '+14155550100'],
    'italian leading zero' => ['+39 06 1234 5678', '+390612345678'],
    'extension' => ['+1 (415) 555-0100 ext. 12', '+14155550100;ext=12'],
    'already canonical' => ['+14155550100;ext=12', '+14155550100;ext=12'],
]);

it('returns a national number without a country unchanged', function (): void {
    expect($this->service->normalize(' 555-123-4567 '))->toBe('555-123-4567');
});

it('keeps the extension through the panel input and display', function (): void {
    $e164 = $this->service->formatToE164('US', '4155550100 ext. 12');

    expect($e164)->toBe('+14155550100;ext=12')
        ->and($this->service->parseE164($e164))->toBe(['country' => 'US', 'number' => '4155550100 ext. 12']);
});
```

Append to `tests/Feature/Models/CustomFieldValueNormalizationTest.php`:

```php
it('stores a phone saved outside the panel form as E.164', function (): void {
    $phoneField = CustomField::factory()->create([
        'custom_field_section_id' => $this->linkField->custom_field_section_id,
        'entity_type' => Post::class,
        'code' => 'phone',
        'name' => 'Phone',
        'type' => 'phone',
        'settings' => new CustomFieldSettingsData(allow_multiple: true, max_values: 5),
    ]);
    $post = Post::factory()->create();

    $post->saveCustomFieldValue($phoneField, ['+1 (415) 555-0100', '+1-415-555-0100']);

    $stored = CustomFieldValue::query()->where('entity_id', $post->getKey())->where('custom_field_id', $phoneField->getKey())->firstOrFail();

    expect(collect($stored->json_value)->all())->toBe(['+14155550100']);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Services/CountryPhoneServiceTest.php tests/Feature/Models/CustomFieldValueNormalizationTest.php`
Expected: FAIL (`normalize` undefined; the phone is stored as typed).

- [ ] **Step 3: Implement**

`src/Services/Phone/CountryPhoneService.php` (import `libphonenumber\PhoneNumber as LibPhoneNumber` and `libphonenumber\PhoneNumberFormat`):

```php
    public function normalize(string $value): string
    {
        $trimmed = trim($value);

        try {
            return $this->canonical($this->getPhoneUtil()->parse($trimmed));
        } catch (Throwable) {
            return $trimmed;
        }
    }

    private function canonical(LibPhoneNumber $parsed): string
    {
        $e164 = $this->getPhoneUtil()->format($parsed, PhoneNumberFormat::E164);
        $extension = $parsed->getExtension();

        return filled($extension) ? "{$e164};ext={$extension}" : $e164;
    }
```

Replace the `try` body of `formatToE164()`:

```php
        try {
            return $this->canonical($this->getPhoneUtil()->parse($number, strtoupper($country)));
        } catch (Throwable) {
```

In `parseE164()`, replace the two lines after `$parsed = ...`:

```php
            $parsed = $this->getPhoneUtil()->parse($e164);
            $nationalNumber = (string) $parsed->getNationalNumber();
            $extension = $parsed->getExtension();

            return ['country' => $country, 'number' => filled($extension) ? "{$nationalNumber} ext. {$extension}" : $nationalNumber];
```

`src/FieldTypeSystem/Definitions/PhoneFieldType.php`, add (import `Relaticle\CustomFields\Services\Phone\CountryPhoneService`):

```php
    public function setValue(string $value): string
    {
        return resolve(CountryPhoneService::class)->normalize($value);
    }
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Feature/Services tests/Feature/Models tests/Feature/Filament/Components/PhoneInputComponentTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src tests
git commit -m "feat: store phones as e.164 and keep extensions"
```

### Task 3: Link domain variant

**Files:**
- Modify: `src/FieldTypeSystem/Definitions/LinkFieldType.php`
- Test: `tests/Feature/Models/CustomFieldValueNormalizationTest.php`

**Interfaces:**
- Consumes: `CustomField::setting('link_variant')` (reads `settings.additional.link_variant`).
- Produces: `LinkFieldType::normalize()` returns the bare lowercase host when `link_variant` is `domain`, else `setValue()`.

- [ ] **Step 1: Write the failing test**

```php
it('stores a domain-variant link as its bare lowercase host', function (string $input): void {
    $this->linkField->update(['settings' => new CustomFieldSettingsData(
        allow_multiple: true,
        max_values: 5,
        additional: ['link_variant' => 'domain'],
    )]);

    expect(SafeValueConverter::toDbSafe([$input], 'link', $this->linkField->refresh()))->toBe(['acme.com']);
})->with([
    'scheme, www and path' => 'https://www.Acme.com/pricing?x=1#top',
    'bare' => 'acme.com',
    'userinfo and port' => 'http://user:secret@acme.com:8080/',
    'trailing dot' => 'ACME.COM.',
    'padded' => '  www.acme.com  ',
]);

it('keeps the path of a url-variant link', function (): void {
    expect(SafeValueConverter::toDbSafe(['https://www.linkedin.com/company/acme'], 'link', $this->linkField))
        ->toBe(['www.linkedin.com/company/acme']);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest tests/Feature/Models/CustomFieldValueNormalizationTest.php --filter=domain-variant`
Expected: FAIL (scheme stripped only).

- [ ] **Step 3: Implement**

`src/FieldTypeSystem/Definitions/LinkFieldType.php` (imports `Illuminate\Support\Str`, `Relaticle\CustomFields\Models\CustomField`):

```php
    public function normalize(string $value, CustomField $customField): string
    {
        if ($customField->setting('link_variant') !== 'domain') {
            return $this->setValue($value);
        }

        return (string) Str::of($value)
            ->trim()
            ->lower()
            ->replaceMatches('#^[a-z][a-z0-9+.-]*://#', '')
            ->replaceMatches('#^[^@/]*@#', '')
            ->before('/')
            ->before('?')
            ->before('#')
            ->replaceMatches('#:\d+$#', '')
            ->replaceMatches('#^(www\.)+#', '')
            ->rtrim('.');
    }
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Feature/Models tests/Feature/Rules`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src tests
git commit -m "feat: add a domain variant to the link field type"
```

### Task 4: Grandfather a record's own unique values on save

**Files:**
- Modify: `src/Rules/UniqueCustomFieldValue.php`
- Modify: `src/Services/ValidationService.php:234-236`
- Test: `tests/Feature/Rules/UniqueCustomFieldValueTest.php`

**Interfaces:**
- Produces: `new UniqueCustomFieldValue(CustomField $customField, string|int|null $ignoreEntityId = null, bool $exceptHeldValues = false)`. `ValidationService` passes `exceptHeldValues: true`; `UsesCustomFields::takenUniqueCustomFieldValues()` (restore) keeps the strict default.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Rules/UniqueCustomFieldValueTest.php`:

```php
it('lets a record keep a unique value another record already shares', function (): void {
    $kept = Post::factory()->create();
    $other = Post::factory()->create();
    DB::table('custom_field_values')->insert([
        ['id' => (string) str()->ulid(), 'entity_type' => (new Post)->getMorphClass(), 'entity_id' => $kept->getKey(), 'custom_field_id' => $this->linkField->getKey(), 'json_value' => json_encode(['acme.com'])],
        ['id' => (string) str()->ulid(), 'entity_type' => (new Post)->getMorphClass(), 'entity_id' => $other->getKey(), 'custom_field_id' => $this->linkField->getKey(), 'json_value' => json_encode(['acme.com'])],
    ]);

    $passes = validator(['v' => ['acme.com']], ['v' => [new UniqueCustomFieldValue($this->linkField, $kept->getKey(), exceptHeldValues: true)]])->passes();

    expect($passes)->toBeTrue();
});

it('rejects a value the record did not hold before in any format', function (): void {
    $taken = Post::factory()->create();
    $editing = Post::factory()->create();
    DB::table('custom_field_values')->insert([
        'id' => (string) str()->ulid(), 'entity_type' => (new Post)->getMorphClass(), 'entity_id' => $taken->getKey(), 'custom_field_id' => $this->linkField->getKey(), 'json_value' => json_encode(['acme.com']),
    ]);

    $passes = validator(['v' => ['https://acme.com']], ['v' => [new UniqueCustomFieldValue($this->linkField, $editing->getKey(), exceptHeldValues: true)]])->passes();

    expect($passes)->toBeFalse();
});

it('still blocks restoring a trashed record whose value is taken', function (): void {
    $trashed = Post::factory()->create();
    $trashed->saveCustomFieldValue($this->linkField, ['acme.com']);
    $trashed->delete();
    Post::factory()->create()->saveCustomFieldValue($this->linkField, ['acme.com']);

    expect($trashed->takenUniqueCustomFieldValues())->not->toBeEmpty();
});
```

If the table's id column or tenant column differ in the package test schema, read `tests/database` migrations and match them before running.

- [ ] **Step 2: Run to verify the first test fails**

Run: `vendor/bin/pest tests/Feature/Rules/UniqueCustomFieldValueTest.php`
Expected: the first new test FAILS (unknown named argument), the restore test PASSES.

- [ ] **Step 3: Implement**

Constructor:

```php
    public function __construct(
        private readonly CustomField $customField,
        private readonly string|int|null $ignoreEntityId = null,
        private readonly bool $exceptHeldValues = false,
    ) {}
```

In `validate()`, after `$normalizedByOriginal` is built and before the empty check:

```php
        if ($this->exceptHeldValues && $this->ignoreEntityId !== null) {
            $held = $this->heldValues($fieldType);
            $normalizedByOriginal = $normalizedByOriginal->reject(fn (string $normalized): bool => in_array($normalized, $held, true));
        }
```

Add:

```php
    /**
     * @return list<string>
     */
    private function heldValues(?FieldTypeDefinitionInterface $fieldType): array
    {
        $valueColumn = $this->customField->getValueColumn();
        $entityClass = Relation::getMorphedModel($this->customField->entity_type) ?? $this->customField->entity_type;

        $stored = CustomFields::newValueModel()->newQuery()
            ->where('custom_field_id', $this->customField->getKey())
            ->where('entity_type', (new $entityClass)->getMorphClass())
            ->where('entity_id', $this->ignoreEntityId)
            ->first()?->getAttribute($valueColumn);

        return collect($stored)
            ->filter(fn (mixed $value): bool => is_scalar($value) && filled($value))
            ->map(fn (mixed $value): string => $fieldType instanceof BaseFieldType ? $fieldType->normalize((string) $value, $this->customField) : (string) $value)
            ->values()
            ->all();
    }
```

`src/Services/ValidationService.php`:

```php
            $rules[] = new UniqueCustomFieldValue($customField, $ignoreEntityId, exceptHeldValues: true);
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Feature/Rules tests/Feature/Models`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src tests
git commit -m "feat: grandfather a record's own unique values on save"
```

### Task 5: Merge the package PR (tag later)

**Status:** PR #244 merged into `3.x` on 2026-10-02. The v3.12.0 tag was pushed and then withdrawn at the user's request (release, tag and the bot's CHANGELOG commit removed via #245): nothing is tagged until both PRs are finalized end to end. The tag moves to Task 17. Steps 4 and 5 below now run there.

**Checkpoint: needs the user's explicit go-ahead before tagging.**

- [ ] **Step 1:** Run the full package gate: `composer test`. Expected: green.
- [ ] **Step 2:** Add a `## v3.12.0` entry to `CHANGELOG.md`: values are normalized on every write path through `BaseFieldType::normalize()`; phones store E.164 with `;ext=`; links gain a `link_variant: domain` setting; uniqueness grandfathers a record's own values on save while restore stays strict.
- [ ] **Step 3:** Push the branch and open a PR to `3.x` (`gh pr create --base 3.x`). Wait for CI; merge after the user approves.
- [ ] **Step 4:** `gh release create v3.12.0 --target 3.x --latest --notes-file <changelog excerpt>`.
- [ ] **Step 5:** Verify Packagist saw the tag (it can miss tags pushed close together): `curl -s https://repo.packagist.org/p2/relaticle/custom-fields.json | grep -c '"v3.12.0"'`. Expected: `1` or more. If `0`, trigger the Packagist update hook from the package page and re-check.

---

## Part B: app

All paths below are in `relaticle` on `feat/custom-field-filter-contract-v1`.

### Task 6: Adopt v3.12 and declare the domain variant

**Files:**
- Modify: `composer.json` (`"relaticle/custom-fields": "3.x-dev as 3.12.0"` until the tag in Task 17), `composer.lock`
- Modify: `packages/ImportWizard/src/Jobs/ExecuteImportJob.php:526`
- Modify: `packages/OnboardSeed/src/Support/BulkCustomFieldValueWriter.php:26`
- Modify: `app/Enums/CustomFields/CustomFieldTrait.php`, `app/Enums/CustomFields/CompanyField.php`
- Modify: `app/Listeners/CreateWorkspaceCustomFields.php:104-110`
- Create: `database/migrations/2026_10_03_000000_set_domain_link_variant_on_company_domains.php`
- Test: `tests/Feature/Api/V1/CompaniesApiTest.php`, `tests/Feature/Mcp/PeopleToolsTest.php`, `tests/Feature/ImportWizard/Jobs/ExecuteImportJobFieldTypeTest.php`

**Interfaces:**
- Consumes: Task 1 to 4 behavior through `relaticle/custom-fields` 3.12.
- Produces: `CustomFieldTrait::additionalSettings(): array` (default `[]`); `CompanyField::DOMAINS` returns `['link_variant' => 'domain']`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Api/V1/CompaniesApiTest.php`, in the store `describe`:

```php
    it('stores company domains as bare hosts', function (): void {
        Sanctum::actingAs($this->user);

        $id = $this->postJson('/api/v1/companies', [
            'name' => 'Acme',
            'custom_fields' => ['domains' => ['https://www.Acme.com/pricing']],
        ])->assertCreated()->json('data.id');

        $domains = CustomField::query()->where('code', 'domains')->where('entity_type', 'company')->firstOrFail();

        expect(collect(Company::query()->findOrFail($id)->getCustomFieldValue($domains))->all())->toBe(['acme.com']);
    });

    it('rejects a domain another company already uses in another format', function (): void {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/companies', ['name' => 'Acme', 'custom_fields' => ['domains' => ['acme.com']]])->assertCreated();

        $this->postJson('/api/v1/companies', ['name' => 'Acme 2', 'custom_fields' => ['domains' => ['https://www.acme.com/']]])
            ->assertUnprocessable();
    });
```

`tests/Feature/Mcp/PeopleToolsTest.php`:

```php
it('stores a phone written through mcp as e.164', function (): void {
    $response = RelaticleServer::actingAs($this->user)->tool(CreatePeopleTool::class, [
        'name' => 'Ana',
        'custom_fields' => ['phone_number' => ['+1 (415) 555-0100']],
    ]);

    $response->assertOk();

    $person = People::query()->where('name', 'Ana')->firstOrFail();
    $field = CustomField::query()->where('code', 'phone_number')->where('entity_type', 'people')->firstOrFail();

    expect(collect(CustomFieldValue::query()->where('entity_id', $person->getKey())->where('custom_field_id', $field->getKey())->value('json_value'))->all())
        ->toBe(['+14155550100']);
});
```

In `ExecuteImportJobFieldTypeTest.php`, change the two existing expectations to the canonical forms (the old assertions pinned the pre-normalization behavior):

```php
    expect($jsonValue)->toBeArray()
        ->toContain('+15550101')
        ->toContain('+442079460958');
```

```php
    expect($jsonValue)->toContain('example.com');
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact --filter="stores company domains|another format|e.164|imports phone|imports link"`
Expected: FAIL (values stored as typed).

- [ ] **Step 3: Implement**

```bash
composer require "relaticle/custom-fields:3.x-dev as 3.12.0" -W
```

The package changes are merged on `3.x` but not tagged; Task 17 tags v3.12.0 and switches this constraint to `^3.12` after the end-to-end walk.

`ExecuteImportJob.php:526`: `$safeValue = SafeValueConverter::toDbSafe($value, $cf->type, $cf);`
`BulkCustomFieldValueWriter.php:26`: `$safeValue = SafeValueConverter::toDbSafe($value, $customField->type, $customField);`

`CustomFieldTrait.php`, add:

```php
    /**
     * @return array<string, mixed>
     */
    public function additionalSettings(): array
    {
        return [];
    }
```

`CompanyField.php`, add:

```php
    /**
     * @return array<string, mixed>
     */
    public function additionalSettings(): array
    {
        return match ($this) {
            self::DOMAINS => ['link_variant' => 'domain'],
            default => [],
        };
    }
```

`CreateWorkspaceCustomFields.php`, add to the `CustomFieldSettingsData` constructor call: `additional: $enum->additionalSettings(),`.

Migration:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('custom_fields')
            ->where('entity_type', 'company')
            ->where('code', 'domains')
            ->where('type', 'link')
            ->eachById(function (object $field): void {
                $settings = json_decode($field->settings ?? '{}', true) ?: [];
                $settings['additional'] = [...($settings['additional'] ?? []), 'link_variant' => 'domain'];

                DB::table('custom_fields')->where('id', $field->id)->update(['settings' => json_encode($settings)]);
            });
    }
};
```

- [ ] **Step 3b: Import matching uses the canonical form**

Stored phones and domains are now canonical, but CSV matching compares raw lowercased values (`packages/ImportWizard/src/Support/EntityLinkResolver.php`, `resolveViaJsonColumn()`), so a re-import of `+1 415-555-0100` would miss the person stored as `+14155550100` and create a duplicate. Normalize each CSV value through the field type before the lookup and key the results back by the lowercased original:

```php
        $field = CustomField::query()->withoutGlobalScopes()->find($customFieldId);
        $definition = $field instanceof CustomField ? CustomFieldsType::getFieldTypeInstance($field->type) : null;
        $canonicalByOriginal = [];

        foreach ($uniqueValues as $value) {
            $canonical = $definition instanceof BaseFieldType && $field instanceof CustomField ? $definition->normalize($value, $field) : $value;
            $canonicalByOriginal[mb_strtolower($value)] = mb_strtolower($canonical);
        }

        $uniqueValues = array_values(array_unique($canonicalByOriginal));
```

Keep the existing query over `$uniqueValues`, then return `array_filter(array_map(fn (string $canonical): int|string|null => $results[$canonical] ?? null, $canonicalByOriginal), fn (int|string|null $id): bool => $id !== null)`. Before editing, read every caller of `resolveViaJsonColumn()` and confirm they look results up by the lowercased CSV value; if one uses another key, map to that key instead.

Test in `tests/Feature/ImportWizard/Jobs/ExecuteImportJobDeduplicationTest.php`, following that file's fixtures: a person stored with phone `+14155550100`, a CSV row with phone `+1 415-555-0100` matched on phone, and the import updates that person instead of creating a second one.

- [ ] **Step 4: Run tests, then the custom field and import suites**

Run: `php artisan test --compact --filter="stores company domains|another format|e.164|imports phone|imports link|re-import"` then `php artisan test --compact tests/Feature/ImportWizard tests/Feature/CustomFields tests/Feature/Api`
Expected: PASS. If another existing test pinned a raw scheme or formatted phone, update its expectation to the canonical form and note it in the commit body.

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock packages app database tests
git commit -m "feat: normalize phones and company domains on every write"
```

### Task 7: Backfill command

**Files:**
- Create: `app/Console/Commands/NormalizeCustomFieldValuesCommand.php`
- Create: `database/migrations/2026_10_03_000100_queue_custom_field_value_normalization.php`
- Test: `tests/Feature/Commands/NormalizeCustomFieldValuesCommandTest.php`

**Interfaces:**
- Consumes: `BaseFieldType::normalize()` via `CustomFieldsType::getFieldTypeInstance()`.
- Produces: `php artisan custom-fields:normalize-values {--force}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Console\Commands\NormalizeCustomFieldValuesCommand;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use Illuminate\Support\Facades\DB;

mutates(NormalizeCustomFieldValuesCommand::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
});

function rawValue(string $entityId, CustomField $field, array $value): void
{
    DB::table('custom_field_values')->updateOrInsert(
        ['entity_id' => $entityId, 'custom_field_id' => $field->getKey()],
        ['id' => (string) str()->ulid(), 'tenant_id' => $field->tenant_id, 'entity_type' => $field->entity_type, 'json_value' => json_encode($value)],
    );
}

function storedValue(string $entityId, CustomField $field): array
{
    return json_decode((string) DB::table('custom_field_values')->where('entity_id', $entityId)->where('custom_field_id', $field->getKey())->value('json_value'), true);
}

function systemField(string $workspaceId, string $entityType, string $code): CustomField
{
    return CustomField::query()->withoutGlobalScopes()->where('tenant_id', $workspaceId)->where('entity_type', $entityType)->where('code', $code)->firstOrFail();
}

it('reports what it would change and writes nothing without force', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $phone = systemField($this->workspace->getKey(), 'people', 'phone_number');
    rawValue($person->getKey(), $phone, ['+1 415-555-0100', '555-123-4567']);

    $this->artisan('custom-fields:normalize-values')
        ->expectsOutputToContain('1 value(s) would change')
        ->expectsOutputToContain('1 national phone number(s) have no country code')
        ->assertSuccessful();

    expect(storedValue($person->getKey(), $phone))->toBe(['+1 415-555-0100', '555-123-4567']);
});

it('normalizes phones and domains with force and changes nothing on a second run', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $phone = systemField($this->workspace->getKey(), 'people', 'phone_number');
    $domains = systemField($this->workspace->getKey(), 'company', 'domains');
    rawValue($person->getKey(), $phone, ['+1 415-555-0100']);
    rawValue($company->getKey(), $domains, ['https://www.Acme.com/', 'acme.com']);

    $this->artisan('custom-fields:normalize-values', ['--force' => true])->assertSuccessful();

    expect(storedValue($person->getKey(), $phone))->toBe(['+14155550100'])
        ->and(storedValue($company->getKey(), $domains))->toBe(['acme.com']);

    $this->artisan('custom-fields:normalize-values', ['--force' => true])
        ->expectsOutputToContain('0 value(s) changed')
        ->assertSuccessful();
});

it('reports domains two companies share after normalization', function (): void {
    $first = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $second = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $domains = systemField($this->workspace->getKey(), 'company', 'domains');
    rawValue($first->getKey(), $domains, ['https://acme.com']);
    rawValue($second->getKey(), $domains, ['acme.com']);

    $this->artisan('custom-fields:normalize-values')
        ->expectsOutputToContain("Workspace {$this->workspace->getKey()}: acme.com is shared by 2 companies")
        ->assertSuccessful();
});
```

Before running, check the `custom_field_values` columns in `database/migrations/2025_02_07_192236_create_custom_fields_table.php` (tenant column name, id type) and adjust `rawValue()` to match.

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact tests/Feature/Commands/NormalizeCustomFieldValuesCommandTest.php`
Expected: FAIL (command not defined).

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CustomField;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Relaticle\CustomFields\Facades\CustomFieldsType;
use Relaticle\CustomFields\FieldTypeSystem\BaseFieldType;

#[Description('Re-run every phone and link custom field value through its field type normalizer')]
#[Signature('custom-fields:normalize-values {--force : Write changes instead of reporting them}')]
final class NormalizeCustomFieldValuesCommand extends Command
{
    private int $changed = 0;

    private int $national = 0;

    /** @var array<string, array<string, list<string>>> */
    private array $domainOwners = [];

    public function handle(): int
    {
        $write = (bool) $this->option('force');

        CustomField::query()
            ->withoutGlobalScopes()
            ->whereIn('type', ['phone', 'link'])
            ->each(function (CustomField $field) use ($write): void {
                $this->info("Normalizing {$field->entity_type}.{$field->code} in workspace {$field->tenant_id}...");
                $this->normalizeField($field, $write);
            });

        $this->reportCollisions();

        $this->comment($write
            ? "{$this->changed} value(s) changed."
            : "{$this->changed} value(s) would change. Re-run with --force to write.");

        if ($this->national > 0) {
            $this->comment("{$this->national} national phone number(s) have no country code and were left as they are.");
        }

        return self::SUCCESS;
    }

    private function normalizeField(CustomField $field, bool $write): void
    {
        $definition = CustomFieldsType::getFieldTypeInstance($field->type);

        if (! $definition instanceof BaseFieldType) {
            return;
        }

        $isDomain = $field->type === 'link' && $field->setting('link_variant') === 'domain';

        DB::table('custom_field_values')
            ->where('custom_field_id', $field->getKey())
            ->whereNotNull('json_value')
            ->chunkById(500, function ($rows) use ($field, $definition, $isDomain, $write): void {
                foreach ($rows as $row) {
                    $stored = json_decode((string) $row->json_value, true);

                    if (! is_array($stored)) {
                        continue;
                    }

                    $normalized = array_values(array_unique(array_map(
                        fn (mixed $item): mixed => is_string($item) && $item !== '' ? $definition->normalize($item, $field) : $item,
                        $stored,
                    ), SORT_REGULAR));

                    $this->countNational($field, $normalized);
                    $this->collectDomains($field, $isDomain, (string) $row->entity_id, $normalized);

                    if ($normalized === $stored) {
                        continue;
                    }

                    $this->changed++;

                    if ($write) {
                        DB::table('custom_field_values')->where('id', $row->id)->update(['json_value' => json_encode($normalized)]);
                    }
                }
            });
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function countNational(CustomField $field, array $values): void
    {
        if ($field->type !== 'phone') {
            return;
        }

        $this->national += count(array_filter($values, fn (mixed $value): bool => is_string($value) && ! str_starts_with($value, '+')));
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function collectDomains(CustomField $field, bool $isDomain, string $entityId, array $values): void
    {
        if (! $isDomain) {
            return;
        }

        foreach ($values as $value) {
            if (is_string($value)) {
                $this->domainOwners[(string) $field->tenant_id][$value][] = $entityId;
            }
        }
    }

    private function reportCollisions(): void
    {
        foreach ($this->domainOwners as $workspaceId => $domains) {
            foreach ($domains as $domain => $entityIds) {
                $owners = array_values(array_unique($entityIds));

                if (count($owners) > 1) {
                    $this->warn("Workspace {$workspaceId}: {$domain} is shared by ".count($owners).' companies ('.implode(', ', $owners).').');
                }
            }
        }
    }
}
```

`chunkById` needs an ordered unique `id`; `custom_field_values.id` is a ULID primary key. If the callback's `$rows` parameter cannot be typed precisely, type it `Collection` (`Illuminate\Support\Collection`) to keep type coverage at 100%.

Migration `2026_10_03_000100_queue_custom_field_value_normalization.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    public function up(): void
    {
        Artisan::queue('custom-fields:normalize-values', ['--force' => true])
            ->onQueue('imports')
            ->afterCommit();
    }
};
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact tests/Feature/Commands/NormalizeCustomFieldValuesCommandTest.php tests/Arch/ConventionsTest.php`
Expected: PASS (ConventionsTest confirms the migration names an existing command).

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/NormalizeCustomFieldValuesCommand.php database/migrations tests/Feature/Commands/NormalizeCustomFieldValuesCommandTest.php
git commit -m "feat: backfill phones and links into their canonical form"
```

### Task 8: Move the filter classes to app/Support/Filters

Pure rename; behavior unchanged.

**Files:**
- Move: `app/Mcp/Filters/CustomFieldFilter.php`, `CustomFieldAllowedFilter.php`, `CustomFieldSort.php` to `app/Support/Filters/`
- Modify: every file that imports `App\Mcp\Filters\*` (11 files: `grep -rln 'App\\Mcp\\Filters' app packages tests`)

- [ ] **Step 1:** `mkdir -p app/Support/Filters && git mv app/Mcp/Filters/*.php app/Support/Filters/ && rmdir app/Mcp/Filters`
- [ ] **Step 2:** In each moved file change `namespace App\Mcp\Filters;` to `namespace App\Support\Filters;`.
- [ ] **Step 3:** `grep -rl 'App\\Mcp\\Filters' app packages tests | xargs sed -i '' 's/App\\Mcp\\Filters/App\\Support\\Filters/g'`
- [ ] **Step 4:** Run `composer dump-autoload && vendor/bin/phpstan analyse && php artisan test --compact tests/Feature/Mcp/Filters tests/Feature/Api/V1/OpportunitiesApiTest.php tests/Arch`. Expected: PASS.
- [ ] **Step 5:** Commit: `git commit -am "refactor: move filter classes to app/Support/Filters"`

### Task 9: `$`-prefixed operators for custom fields

**Files:**
- Modify: `app/Mcp/Schema/CustomFieldFilterSchema.php` (operator keys)
- Modify: `app/Support/Filters/CustomFieldFilter.php`
- Modify: `app/Mcp/Schema/CustomFieldSchema.php` (`USAGE`), `app/Mcp/Tools/BaseListTool.php` (`filter` description), `app/Scribe/Strategies/GetFromSpatieQueryBuilder.php` (custom field description), `packages/Chat/src/Services/Tools/CustomFieldsFilterDescriber.php`
- Modify: `lang/en/validation.php`
- Test: `tests/Feature/Mcp/Filters/CustomFieldFilterTest.php`, `tests/Feature/Api/V1/OpportunitiesApiTest.php`, `tests/Feature/CRM/SurfaceParityTest.php`, `tests/Feature/Documentation/ApiDocumentationGenerationTest.php`, and every test sending custom field filters

**Interfaces:**
- Produces: `CustomFieldFilterSchema::operatorsForType()` keys `$eq`, `$gt`, `$gte`, `$lt`, `$lte`, `$contains`, `$in`, `$not_in`, `$has_any`, `$has_none`, `$is_empty`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Api/V1/OpportunitiesApiTest.php`, in `describe('filtering and sorting')`:

```php
    it('filters a custom field with a $-prefixed operator', function (): void {
        Sanctum::actingAs($this->user);
        $stage = CustomField::query()->where('entity_type', 'opportunity')->where('code', 'stage')->firstOrFail();
        $won = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
        Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
        $won->saveCustomFieldValue($stage, (string) $stage->options()->where('name', 'Closed Won')->value('id'));

        $ids = collect($this->getJson('/api/v1/opportunities?filter[custom_fields][stage][$eq]=Closed%20Won')->assertOk()->json('data'))->pluck('id');

        expect($ids->all())->toBe([$won->id]);
    });

    it('names the $ form when an operator has no sigil', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/opportunities?filter[custom_fields][stage][eq]=Closed%20Won')
            ->assertUnprocessable()
            ->assertJsonFragment(['Operators start with $. Use $eq.']);
    });

    it('compares the text true without turning it into a boolean', function (): void {
        Sanctum::actingAs($this->user);
        $motto = customFieldForOpportunities($this->workspace, 'motto', 'Motto', 'text');
        $match = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
        Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
        $match->saveCustomFieldValue($motto, 'true');

        $ids = collect($this->getJson('/api/v1/opportunities?filter[custom_fields][motto][$eq]=true')->assertOk()->json('data'))->pluck('id');

        expect($ids->all())->toBe([$match->id]);
    });
```

If the seeded stage options use other labels, read `OpportunityField::STAGE` options and use one of them.

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact --filter="prefixed operator|has no sigil|text true"`
Expected: FAIL (unsupported operator `$eq`).

- [ ] **Step 3: Implement**

`CustomFieldFilterSchema.php`: prefix every operator constant and list with `$`:

```php
    private const array NUMERIC_OPERATORS = ['$eq', '$gt', '$gte', '$lt', '$lte'];

    private const array STRING_OPERATORS = ['$eq', '$contains'];

    private const array BOOLEAN_OPERATORS = ['$eq'];
```

and in `operatorsForType()` use `['$has_any', '$has_none']`, `['$eq']`, `['$in', '$not_in']`, and `'$is_empty' => ['type' => 'boolean']`.

`CustomFieldFilter.php`:
- `OPERATOR_MAP` keys become `'$eq' => '='`, `'$gt' => '>'`, `'$gte' => '>='`, `'$lt' => '<'`, `'$lte' => '<='`.
- `applyCondition()` match arms become `'$not_in'`, `'$has_none'`, `'$is_empty'`, and inside: `'$eq', '$gt', '$gte', '$lt', '$lte'`, `'$contains'`, `'$in'`, `'$has_any'`.
- Before the unsupported-operator check in `__invoke()`:

```php
                if (! str_starts_with((string) $operator, '$') && isset($supportedOperators['$'.$operator])) {
                    $this->invalid(__('validation.filter.operator_sigil', ['operator' => '$'.$operator]));
                }
```

- `toString()` accepts the boolean Spatie made out of `true`/`false`:

```php
    private function toString(mixed $operand, ?string $format): ?string
    {
        if (is_bool($operand)) {
            $operand = $operand ? 'true' : 'false';
        }

        if (! is_string($operand)) {
            return null;
        }
```

`lang/en/validation.php`, add a `filter` group next to `custom_field`:

```php
    'filter' => [
        'operator_sigil' => 'Operators start with $. Use :operator.',
    ],
```

and update the examples inside `custom_field.operator_object` to `{"$eq": "..."}`.

Descriptions: in `CustomFieldSchema::USAGE`, the `BaseListTool` `filter` description and `CustomFieldsFilterDescriber` (lines and example), write every operator with its `$` (for example `{"$eq": "Closed Won"}`, `$not_in and $has_none also match records where the field is empty`). In `GetFromSpatieQueryBuilder`, move the custom field description into a constant and use it in `extractCustomFieldFilter()`:

```php
    public const string CUSTOM_FIELD_FILTER_DESCRIPTION = 'Filter by a custom field value. Single choice: $eq, $in, $not_in. Multi choice, tags, email, phone, link: $has_any, $has_none. Text: $eq, $contains. Numbers and dates: $eq, $gt, $gte, $lt, $lte. Checkbox, toggle: $eq. Every type: $is_empty (1, 0, true or false). Select, radio, toggle-buttons, multi-select and checkbox-list values take an option label or ID; an unknown one returns 422. Choice lists split on commas, so repeat the parameter with [] when a label contains a comma. Tags, email, phone and link values match the exact stored value, so repeat [] to send several. $not_in and $has_none also match records where the field is empty. Up to 10 conditions, 100 values per list. Example for an opportunity stage field: filter[custom_fields][stage][$in]=Qualification,Prospecting.';
```

Tests: every filter operator a test sends gets its `$`. Find them with:

```bash
grep -rln -E "custom_fields\]\[[a-z_]+\]\[(eq|gt|gte|lt|lte|contains|in|not_in|has_any|has_none|is_empty)\]" tests
grep -rn -E "'(eq|gt|gte|lt|lte|contains|in|not_in|has_any|has_none|is_empty)' =>" tests/Feature/Mcp tests/Feature/Chat tests/Feature/Api tests/Feature/CRM
```

Change only filter operands (keys inside a `filter`/`custom_fields` filter array or `filter[...]` URL), never write payloads. In `SurfaceParityTest` and `ApiDocumentationGenerationTest`, the operator-extraction regex becomes `/\$[a-z_]+/`, and the published operator list keeps only keys starting with `$` (`->filter(fn (string $operator): bool => str_starts_with($operator, '$'))`), so a sub-field key added later is not mistaken for an operator.

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact tests/Feature/Mcp tests/Feature/Chat tests/Feature/Api tests/Feature/CRM tests/Feature/Documentation`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app lang packages tests
git commit -m "feat: prefix custom field filter operators with \$"
```

### Task 10: Entity filter registry, native fields and relation ids

The largest task: one filter param replaces every flat param on every surface.

**Files:**
- Create: `app/Support/Filters/FilterKind.php`, `FilterDefinition.php`, `EntityFilters.php`, `FilterErrors.php`, `Operand.php`, `NativeFilter.php`, `RelationFilter.php`, `StaleDaysFilter.php`, `AssignedToMeFilter.php`
- Rename: `app/Support/Filters/CustomFieldAllowedFilter.php` to `TreeAllowedFilter.php`
- Modify: `app/Support/Filters/CustomFieldFilter.php` (takes the user, relative error keys, uses `Operand`)
- Modify: `app/Actions/{Company/ListCompanies,People/ListPeople,Opportunity/ListOpportunities,Task/ListTasks,Note/ListNotes}.php`
- Modify: `app/Mcp/Tools/BaseListTool.php` and the five `app/Mcp/Tools/*/List*Tool.php`
- Modify: `packages/Chat/src/Tools/BaseReadListTool.php` and the five `packages/Chat/src/Tools/*/List*Tool.php`
- Modify: `app/Scribe/Strategies/GetFromSpatieQueryBuilder.php`
- Modify: `lang/en/validation.php`
- Test: `tests/Feature/Api/V1/ListFilterTest.php` (create), plus every test using a removed param (see Step 3j)

**Interfaces:**
- Produces:
  - `enum FilterKind: string { Text, DateTime, Enum, Members, Relation, Computed }`
  - `FilterDefinition::text()`, `dateTime()`, `enum(class-string<BackedEnum>)`, `members()`, `relation(CrmEntity)`, `computed(class-string<Filter>, list<string> $operators)`; properties `kind`, `related`, `enumClass`, `filterClass`; `operators(): list<string>`
  - `EntityFilters::definitions(CrmEntity): array<string, FilterDefinition>` (static, no user, no DB); `new EntityFilters(User)`; `->for(CrmEntity): list<AllowedFilter>`; `EntityFilters::GRAMMAR` (string)
  - `FilterErrors::at(string $path, string $message): ValidationException`, `FilterErrors::prefix(ValidationException, string|int $segment): ValidationException`
  - `TreeAllowedFilter` (AllowedFilter subclass) prefixing errors with its name, and `filter.` at the top level
  - `new CustomFieldFilter(string $entityType, User $user)`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Api/V1/ListFilterTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Support\Filters\EntityFilters;
use App\Support\Filters\NativeFilter;
use App\Support\Filters\RelationFilter;
use Laravel\Sanctum\Sanctum;

mutates(EntityFilters::class, NativeFilter::class, RelationFilter::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
    Sanctum::actingAs($this->user);
});

function listIds(mixed $test, string $entity, array $filter): array
{
    return collect($test->getJson("/api/v1/{$entity}?".http_build_query(['filter' => $filter]))->assertOk()->json('data'))
        ->pluck('id')
        ->sort()
        ->values()
        ->all();
}

it('filters a native text field by an operator object', function (): void {
    $acme = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Robotics']);
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Globex']);

    expect(listIds($this, 'companies', ['name' => ['$contains' => 'acme']]))->toBe([$acme->id]);
});

it('filters created_at by a calendar date', function (): void {
    $this->travelTo('2026-09-01 10:00:00');
    Company::factory()->recycle([$this->user, $this->workspace])->create();
    $this->travelTo('2026-10-01 23:30:00');
    $late = Company::factory()->recycle([$this->user, $this->workspace])->create();

    expect(listIds($this, 'companies', ['created_at' => ['$gte' => '2026-10-01']]))->toBe([$late->id]);
});

it('filters creation_source with $in and $not_in', function (): void {
    $api = Company::factory()->recycle([$this->user, $this->workspace])->create(['creation_source' => CreationSource::API]);
    $web = Company::factory()->recycle([$this->user, $this->workspace])->create(['creation_source' => CreationSource::WEB]);

    expect(listIds($this, 'companies', ['creation_source' => ['$in' => 'api']]))->toBe([$api->id])
        ->and(listIds($this, 'companies', ['creation_source' => ['$not_in' => ['api']]]))->toBe([$web->id]);
});

it('filters a record relation by id and by emptiness', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $linked = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $company->id]);
    $loose = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => null]);

    expect(listIds($this, 'opportunities', ['company' => ['$in' => [$company->id]]]))->toBe([$linked->id])
        ->and(listIds($this, 'opportunities', ['company' => ['$is_empty' => true]]))->toBe([$loose->id])
        ->and(listIds($this, 'opportunities', ['company' => ['$not_in' => [$company->id]]]))->toBe([$loose->id]);
});

it('filters tasks by assignee and by assigned_to_me', function (): void {
    $mine = Task::factory()->recycle([$this->user, $this->workspace])->create();
    $mine->assignees()->attach($this->user);
    Task::factory()->recycle([$this->user, $this->workspace])->create();

    expect(listIds($this, 'tasks', ['assignees' => ['$in' => [$this->user->id]]]))->toBe([$mine->id])
        ->and(listIds($this, 'tasks', ['assigned_to_me' => ['$eq' => true]]))->toBe([$mine->id]);
});

it('returns nothing for a member id from another workspace', function (): void {
    $stranger = User::factory()->withPersonalWorkspace()->create();
    Task::factory()->recycle([$this->user, $this->workspace])->create()->assignees()->attach($this->user);

    expect(listIds($this, 'tasks', ['assignees' => ['$in' => [$stranger->id]]]))->toBe([]);
});

it('keeps a native name and a custom field coded name apart', function (): void {
    $field = app(CreateCustomField::class)->execute($this->user, ['entity_type' => 'company', 'name' => 'Legal name', 'code' => 'name', 'type' => 'text']);
    $byNative = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme']);
    $byCustom = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Other']);
    $byCustom->saveCustomFieldValue($field, 'Acme Holdings');

    expect(listIds($this, 'companies', ['name' => ['$eq' => 'Acme']]))->toBe([$byNative->id])
        ->and(listIds($this, 'companies', ['custom_fields' => ['name' => ['$contains' => 'Acme']]]))->toBe([$byCustom->id]);
});

it('caps a relation id list at one hundred values', function (): void {
    $ids = array_map(fn (): string => (string) str()->ulid(), range(1, 101));

    $this->getJson('/api/v1/tasks?'.http_build_query(['filter' => ['assignees' => ['$in' => $ids]]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.assignees.$in']);
});

it('keys an error by the path of the node to fix', function (): void {
    $this->getJson('/api/v1/opportunities?'.http_build_query(['filter' => ['custom_fields' => ['amount' => ['$contains' => 'x']]]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.custom_fields.amount.$contains']);
});
```

If `CreateCustomField` rejects the code `name`, create the field with the file-local helper pattern from `OpportunitiesApiTest::customFieldForOpportunities()` instead; production holds such fields, so the filter must handle them either way.

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/Api/V1/ListFilterTest.php`
Expected: FAIL (`name` takes a string; `company`, `assignees`, `assigned_to_me` unknown).

- [ ] **Step 3a: `FilterKind`, `FilterDefinition`, `FilterErrors`**

```php
<?php

declare(strict_types=1);

namespace App\Support\Filters;

enum FilterKind: string
{
    case Text = 'text';
    case DateTime = 'date-time';
    case Enum = 'enum';
    case Members = 'members';
    case Relation = 'relation';
    case Computed = 'computed';
}
```

```php
<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Mcp\Schema\CustomFieldFilterSchema;
use BackedEnum;
use Spatie\QueryBuilder\Filters\Filter;

final readonly class FilterDefinition
{
    public const array LINK_OPERATORS = ['$in', '$not_in', '$is_empty'];

    /**
     * @param  class-string<BackedEnum>|null  $enumClass
     * @param  class-string<Filter<*>>|null  $filterClass
     * @param  list<string>  $computedOperators
     */
    private function __construct(
        public FilterKind $kind,
        public ?CrmEntity $related = null,
        public ?string $enumClass = null,
        public ?string $filterClass = null,
        private array $computedOperators = [],
    ) {}

    public static function text(): self
    {
        return new self(FilterKind::Text);
    }

    public static function dateTime(): self
    {
        return new self(FilterKind::DateTime);
    }

    /** @param class-string<BackedEnum> $enumClass */
    public static function enum(string $enumClass): self
    {
        return new self(FilterKind::Enum, enumClass: $enumClass);
    }

    public static function members(): self
    {
        return new self(FilterKind::Members);
    }

    public static function relation(CrmEntity $related): self
    {
        return new self(FilterKind::Relation, related: $related);
    }

    /**
     * @param  class-string<Filter<*>>  $filterClass
     * @param  list<string>  $operators
     */
    public static function computed(string $filterClass, array $operators): self
    {
        return new self(FilterKind::Computed, filterClass: $filterClass, computedOperators: $operators);
    }

    /** @return list<string> */
    public function operators(): array
    {
        return match ($this->kind) {
            FilterKind::Text => array_keys(CustomFieldFilterSchema::operatorsForType(CustomFieldType::TEXT->value)),
            FilterKind::DateTime => array_keys(CustomFieldFilterSchema::operatorsForType(CustomFieldType::DATE_TIME->value)),
            FilterKind::Enum => array_keys(CustomFieldFilterSchema::operatorsForType(CustomFieldType::SELECT->value)),
            FilterKind::Members, FilterKind::Relation => self::LINK_OPERATORS,
            FilterKind::Computed => $this->computedOperators,
        };
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Support\Filters;

use Illuminate\Validation\ValidationException;

final readonly class FilterErrors
{
    public static function at(string $path, string $message): ValidationException
    {
        return ValidationException::withMessages([$path => [$message]]);
    }

    public static function prefix(ValidationException $exception, string|int $segment): ValidationException
    {
        $messages = [];

        foreach ($exception->errors() as $key => $errors) {
            $messages[$key === '' ? (string) $segment : "{$segment}.{$key}"] = $errors;
        }

        return ValidationException::withMessages($messages);
    }
}
```

Filters throw with a key relative to their own node (`''` for the node itself). Run `php artisan tinker --execute 'dump(App\Support\Filters\FilterErrors::prefix(App\Support\Filters\FilterErrors::at("", "x"), "name")->errors());'` and confirm `["name" => ["x"]]` before going on; if `''` is dropped by the message bag, use the constant `FilterErrors::HERE = '.'` as the self key and match it in `prefix()`.

- [ ] **Step 3b: `Operand`**

Move the private coercion helpers out of `CustomFieldFilter` into one static owner so native, relation and custom field filters coerce alike:

```php
<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Mcp\Schema\CustomFieldFilterSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Validator;

final readonly class Operand
{
    /** @return list<string>|null */
    public static function stringList(mixed $operand, bool $splitsStrings): ?array
    {
        if (is_string($operand)) {
            $operand = $splitsStrings ? array_map(trim(...), explode(',', $operand)) : [$operand];
        }

        if (! is_array($operand) || $operand === [] || ! array_is_list($operand)) {
            return null;
        }

        $operand = array_map(static fn (mixed $item): mixed => is_bool($item) ? ($item ? 'true' : 'false') : $item, $operand);

        if (! array_all($operand, static fn (mixed $item): bool => is_string($item) && $item !== '')) {
            return null;
        }

        return $operand;
    }

    /**
     * @return list<string>
     */
    public static function listOrFail(mixed $operand, bool $splitsStrings, string $path, string $expected): array
    {
        $list = self::stringList($operand, $splitsStrings)
            ?? throw FilterErrors::at($path, __('validation.filter.operand_type', ['name' => $path, 'expected' => $expected]));

        if (count($list) > CustomFieldFilterSchema::MAX_LIST_VALUES) {
            throw FilterErrors::at($path, __('validation.filter.too_many_values', ['name' => $path, 'max' => CustomFieldFilterSchema::MAX_LIST_VALUES]));
        }

        return $list;
    }

    public static function string(mixed $operand): ?string
    {
        if (is_bool($operand)) {
            return $operand ? 'true' : 'false';
        }

        return is_string($operand) ? $operand : null;
    }

    public static function date(mixed $operand): ?CarbonImmutable
    {
        if (! is_string($operand) || Validator::make(['date' => $operand], ['date' => ['date']])->fails()) {
            return null;
        }

        return Date::parse($operand);
    }

    public static function isBareDate(string $operand): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $operand) === 1;
    }

    public static function boolean(mixed $operand): ?bool
    {
        if (is_bool($operand)) {
            return $operand;
        }

        if (! is_string($operand) && ! is_int($operand)) {
            return null;
        }

        return filter_var($operand, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    public static function integer(mixed $operand): ?int
    {
        if (is_int($operand)) {
            return $operand;
        }

        $integer = is_string($operand) ? filter_var($operand, FILTER_VALIDATE_INT) : false;

        return $integer === false ? null : $integer;
    }

    public static function number(mixed $operand): int|float|null
    {
        if (is_int($operand) || is_float($operand)) {
            return $operand;
        }

        $number = is_string($operand) ? filter_var($operand, FILTER_VALIDATE_FLOAT) : false;

        return $number === false ? null : $number;
    }
}
```

Spatie turns every query-string `true`/`false` into a boolean, list items included, so `stringList()` maps them back to text: a tag literally named `true` stays reachable (`filter[custom_fields][labels][$has_any][]=true`). Add that case to `tests/Feature/Api/V1/OpportunitiesApiTest.php` next to Task 9's text-`true` test.

In `CustomFieldFilter`, delete `toStringList`, `toString`, `toDate`, `toBoolean`, `toInteger`, `toNumber` and call `Operand::stringList()`, `Operand::string()`/`Operand::date()`, `Operand::boolean()`, `Operand::integer()`, `Operand::number()`. Its existing `too_many_values` check after `normalizeOperand()` stays as it is.

- [ ] **Step 3c: `TreeAllowedFilter`**

`git mv app/Support/Filters/CustomFieldAllowedFilter.php app/Support/Filters/TreeAllowedFilter.php`, then:

```php
<?php

declare(strict_types=1);

namespace App\Support\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

final class TreeAllowedFilter extends AllowedFilter
{
    /**
     * @param  QueryBuilder<*>  $query
     */
    public function filter(QueryBuilder $query, mixed $value): void
    {
        try {
            $this->applyTo($query->getEloquentBuilder(), $value);
        } catch (ValidationException $exception) {
            throw FilterErrors::prefix($exception, 'filter');
        }
    }

    /**
     * @param  Builder<Model>  $builder
     */
    public function applyTo(Builder $builder, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        try {
            ($this->filterClass)($builder, $value, $this->internalName);
        } catch (ValidationException $exception) {
            throw FilterErrors::prefix($exception, $this->getName());
        }
    }
}
```

The override skips Spatie's comma splitting and empty pruning for every tree node (`"Smith, John"` stays whole; `$not_in: []` reaches the filter). In `tests/Arch/ArchTest.php`, rename the two `App\Support\Filters\CustomFieldAllowedFilter` ignore entries (added in Task 8) to `App\Support\Filters\TreeAllowedFilter`.

- [ ] **Step 3d: `CustomFieldFilter` takes the user and throws relative keys**

```php
    public function __construct(
        private string $entityType,
        private User $user,
    ) {}
```

Delete `allowedFilter()` (the registry builds it). In `filterableFields()` use `$this->user->currentWorkspace` instead of `auth()->user()`. Replace `invalid(string $message)` with a path-aware version and pass the path at each call site:

```php
    private function invalid(string $message, string $path = ''): never
    {
        throw FilterErrors::at($path, $message);
    }
```

Paths: unknown field and non-object field use `$fieldCode`; operator errors use `"{$fieldCode}.{$operator}"`; option errors use `"{$fieldCode}.{$operator}"`; the whole-object errors (`filter_not_object`, `filter_code_not_string`, `too_many_conditions`) use `''`. Pass `$operator` into `normalizeOperand()` and `resolveOptions()` already receive it; add it to `optionId()`'s signature.

- [ ] **Step 3e: `NativeFilter`**

```php
<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Support\LikePattern;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * @implements Filter<Model>
 */
final readonly class NativeFilter implements Filter
{
    private const array COMPARISONS = ['$eq' => '=', '$gt' => '>', '$gte' => '>=', '$lt' => '<', '$lte' => '<='];

    public function __construct(private FilterDefinition $definition) {}

    /**
     * @param  Builder<Model>  $query
     */
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $operators = $this->definition->operators();

        if (! is_array($value) || $value === [] || array_is_list($value)) {
            throw FilterErrors::at('', __('validation.filter.operator_object', ['name' => $property, 'operator' => $operators[0]]));
        }

        $column = $query->qualifyColumn($property);

        foreach ($value as $operator => $operand) {
            $operator = (string) $operator;

            if (! str_starts_with($operator, '$') && in_array('$'.$operator, $operators, true)) {
                throw FilterErrors::at($operator, __('validation.filter.operator_sigil', ['operator' => '$'.$operator]));
            }

            if (! in_array($operator, $operators, true)) {
                throw FilterErrors::at($operator, __('validation.filter.unsupported_operator', ['name' => $property, 'operator' => $operator, 'supported' => implode(', ', $operators)]));
            }

            match ($this->definition->kind) {
                FilterKind::Text => $this->text($query, $column, $operator, $operand),
                FilterKind::DateTime => $this->dateTime($query, $column, $operator, $operand),
                default => $this->enum($query, $column, $operator, $operand),
            };
        }
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function text(Builder $query, string $column, string $operator, mixed $operand): void
    {
        if ($operator === '$is_empty') {
            $this->emptiness($query, $column, $operator, $operand, blankIsEmpty: true);

            return;
        }

        $text = Operand::string($operand) ?? throw FilterErrors::at($operator, __('validation.filter.operand_type', ['name' => $operator, 'expected' => 'a string']));

        $operator === '$contains'
            ? $query->where($column, 'ILIKE', '%'.LikePattern::escape($text).'%')
            : $query->where($column, '=', $text);
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function dateTime(Builder $query, string $column, string $operator, mixed $operand): void
    {
        if ($operator === '$is_empty') {
            $this->emptiness($query, $column, $operator, $operand, blankIsEmpty: false);

            return;
        }

        $date = Operand::date($operand) ?? throw FilterErrors::at($operator, __('validation.filter.operand_type', ['name' => $operator, 'expected' => 'a date or date-time']));

        is_string($operand) && Operand::isBareDate($operand)
            ? $query->whereDate($column, self::COMPARISONS[$operator], $date->toDateString())
            : $query->where($column, self::COMPARISONS[$operator], $date->toDateTimeString());
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function enum(Builder $query, string $column, string $operator, mixed $operand): void
    {
        if ($operator === '$is_empty') {
            $this->emptiness($query, $column, $operator, $operand, blankIsEmpty: false);

            return;
        }

        /** @var class-string<BackedEnum> $enumClass */
        $enumClass = $this->definition->enumClass;
        $allowed = array_map(static fn (BackedEnum $case): string => (string) $case->value, $enumClass::cases());
        $values = Operand::listOrFail($operand, splitsStrings: true, path: $operator, expected: 'one of: '.implode(', ', $allowed));
        $unknown = array_first(array_diff($values, $allowed));

        if ($unknown !== null) {
            throw FilterErrors::at($operator, __('validation.filter.enum_value', ['value' => $unknown, 'values' => implode(', ', $allowed)]));
        }

        match ($operator) {
            '$eq', '$in' => $query->whereIn($column, $values),
            default => $query->where(fn (Builder $excluded): Builder => $excluded->whereNotIn($column, $values)->orWhereNull($column)),
        };
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function emptiness(Builder $query, string $column, string $operator, mixed $operand, bool $blankIsEmpty): void
    {
        $empty = Operand::boolean($operand) ?? throw FilterErrors::at($operator, __('validation.filter.operand_type', ['name' => $operator, 'expected' => 'true or false']));

        if ($empty) {
            $query->where(fn (Builder $q): Builder => $blankIsEmpty ? $q->whereNull($column)->orWhere($column, '') : $q->whereNull($column));

            return;
        }

        $blankIsEmpty ? $query->whereNotNull($column)->where($column, '<>', '') : $query->whereNotNull($column);
    }
}
```

- [ ] **Step 3f: `RelationFilter` (link operators only in this task)**

```php
<?php

declare(strict_types=1);

namespace App\Support\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * @implements Filter<Model>
 */
final readonly class RelationFilter implements Filter
{
    public function __construct(private FilterDefinition $definition) {}

    /**
     * @param  Builder<Model>  $query
     */
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        if (! is_array($value) || $value === [] || array_is_list($value)) {
            throw FilterErrors::at('', __('validation.filter.operator_object', ['name' => $property, 'operator' => '$in']));
        }

        foreach ($value as $operator => $operand) {
            $operator = (string) $operator;

            match ($operator) {
                '$in' => $query->whereHas($property, fn (Builder $related): Builder => $related->whereKey($this->ids($operator, $operand))),
                '$not_in' => $query->whereDoesntHave($property, fn (Builder $related): Builder => $related->whereKey($this->ids($operator, $operand))),
                '$is_empty' => $this->emptiness($query, $property, $operand),
                default => throw FilterErrors::at($operator, __('validation.filter.members_ids_only', ['name' => $property])),
            };
        }
    }

    /**
     * @return list<string>
     */
    private function ids(string $operator, mixed $operand): array
    {
        return Operand::listOrFail($operand, splitsStrings: true, path: $operator, expected: 'a list of record IDs');
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function emptiness(Builder $query, string $property, mixed $operand): void
    {
        $empty = Operand::boolean($operand) ?? throw FilterErrors::at('$is_empty', __('validation.filter.operand_type', ['name' => '$is_empty', 'expected' => 'true or false']));

        $empty ? $query->whereDoesntHave($property) : $query->whereHas($property);
    }
}
```

Task 11 adds nested nodes through a shared trait; `$definition` is kept now for that.

- [ ] **Step 3g: computed filters**

```php
<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as DbBuilder;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * @implements Filter<Model>
 */
final readonly class StaleDaysFilter implements Filter
{
    public function __construct(private User $user) {}

    /**
     * @param  Builder<Model>  $query
     */
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $days = is_array($value) && array_keys($value) === ['$gte'] ? Operand::integer($value['$gte']) : null;

        if ($days === null || $days < 1) {
            throw FilterErrors::at('', __('validation.filter.stale_days'));
        }

        $workspaceId = $this->user->currentWorkspace->getKey();

        $query->whereNotExists(fn (DbBuilder $activity) => $activity->from('activity_log')
            ->where('activity_log.workspace_id', $workspaceId)
            ->where('activity_log.subject_type', 'opportunity')
            ->whereColumn('activity_log.subject_id', 'opportunities.id')
            ->where('activity_log.created_at', '>=', now()->subDays($days)));
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * @implements Filter<Model>
 */
final readonly class AssignedToMeFilter implements Filter
{
    public function __construct(private User $user) {}

    /**
     * @param  Builder<Model>  $query
     */
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $assigned = is_array($value) && array_keys($value) === ['$eq'] ? Operand::boolean($value['$eq']) : null;

        if ($assigned === null) {
            throw FilterErrors::at('', __('validation.filter.assigned_to_me'));
        }

        $assigned
            ? $query->whereHas('assignees', fn (Builder $q): Builder => $q->whereKey($this->user->getKey()))
            : $query->whereDoesntHave('assignees', fn (Builder $q): Builder => $q->whereKey($this->user->getKey()));
    }
}
```

- [ ] **Step 3h: `EntityFilters`**

```php
<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Enums\CreationSource;
use App\Enums\CrmEntity;
use App\Models\User;
use Spatie\QueryBuilder\AllowedFilter;

final readonly class EntityFilters
{
    public const string GRAMMAR = 'An object of conditions. Keys starting with $ are keywords: operators ($eq, $gt, $gte, $lt, $lte, $contains, $in, $not_in, $has_any, $has_none, $is_empty) and logic ($and and $or take a list of condition objects, $not takes one and also returns records where the inner fields are empty). Other keys are names: native fields (name, title, created_at, updated_at, creation_source), relations (company, contact, people, opportunities, companies, creator, accountOwner, assignees) and custom_fields, an object keyed by custom field code. Each field takes an operator object such as {"$gte": "2026-10-01"}. A relation takes $in, $not_in or $is_empty on record ids, or conditions on the related record.';

    public function __construct(private User $user) {}

    /**
     * @return array<string, FilterDefinition>
     */
    public static function definitions(CrmEntity $entity): array
    {
        $common = [
            $entity->titleColumn() => FilterDefinition::text(),
            'created_at' => FilterDefinition::dateTime(),
            'updated_at' => FilterDefinition::dateTime(),
            'creation_source' => FilterDefinition::enum(CreationSource::class),
            'creator' => FilterDefinition::members(),
        ];

        return $common + match ($entity) {
            CrmEntity::Company => [
                'accountOwner' => FilterDefinition::members(),
                'people' => FilterDefinition::relation(CrmEntity::People),
                'opportunities' => FilterDefinition::relation(CrmEntity::Opportunity),
            ],
            CrmEntity::People => [
                'company' => FilterDefinition::relation(CrmEntity::Company),
            ],
            CrmEntity::Opportunity => [
                'company' => FilterDefinition::relation(CrmEntity::Company),
                'contact' => FilterDefinition::relation(CrmEntity::People),
                'stale_days' => FilterDefinition::computed(StaleDaysFilter::class, ['$gte']),
            ],
            CrmEntity::Task => [
                'assignees' => FilterDefinition::members(),
                'companies' => FilterDefinition::relation(CrmEntity::Company),
                'people' => FilterDefinition::relation(CrmEntity::People),
                'opportunities' => FilterDefinition::relation(CrmEntity::Opportunity),
                'assigned_to_me' => FilterDefinition::computed(AssignedToMeFilter::class, ['$eq']),
            ],
            CrmEntity::Note => [
                'companies' => FilterDefinition::relation(CrmEntity::Company),
                'people' => FilterDefinition::relation(CrmEntity::People),
                'opportunities' => FilterDefinition::relation(CrmEntity::Opportunity),
            ],
        };
    }

    /**
     * @return list<AllowedFilter>
     */
    public function for(CrmEntity $entity): array
    {
        $filters = [];

        foreach (self::definitions($entity) as $name => $definition) {
            $filters[] = TreeAllowedFilter::custom($name, match ($definition->kind) {
                FilterKind::Text, FilterKind::DateTime, FilterKind::Enum => new NativeFilter($definition),
                FilterKind::Members, FilterKind::Relation => new RelationFilter($definition),
                FilterKind::Computed => new ($definition->filterClass)($this->user),
            });
        }

        $filters[] = TreeAllowedFilter::custom('custom_fields', new CustomFieldFilter($entity->value, $this->user));

        return $filters;
    }
}
```

If PHPStan cannot see that `filterClass` is non-null for the computed arm, assert it in `FilterDefinition` by giving `computed()` a non-nullable private field and exposing `computedFilter(User $user): Filter` there instead of `new ($definition->filterClass)`.

- [ ] **Step 3i: list actions**

In each of the five actions, replace the whole `->allowedFilters(...)` argument list with the registry, keeping fields, includes, sorts and pagination as they are. For `ListTasks`:

```php
        $query = QueryBuilder::for(
            Task::query()->withCustomFieldValues()->whereBelongsTo($user->currentWorkspace),
            $request,
        )
            ->allowedFilters(...new EntityFilters($user)->for(CrmEntity::Task))
            ->allowedFields('id', 'title', 'creator_id', 'created_at', 'updated_at')
```

Drop the now-unused imports (`Arr`, `AllowedFilter`, `Builder`, `DbBuilder`, `CustomFieldFilter`). `ListOpportunities` loses its inline `stale_days` closure (now `StaleDaysFilter`).

- [ ] **Step 3j: surfaces and tests**

MCP `app/Mcp/Tools/BaseListTool.php`:
- Delete `searchFilterName()`, `additionalSchema()`, `additionalFilters()`, `additionalValidationRules()` and their overrides in the five MCP list tools (and the now-unused imports there).
- `schema()` returns only:

```php
        return [
            'filter' => $schema->object()->description(EntityFilters::GRAMMAR.' Field names, operators and options for this workspace are listed under filterable_fields in get-crm-schema.'),
            'sort' => $schema->object()->description('Sort by field. Properties: field (string), direction (asc|desc).'),
            'include' => $schema->array()->description('Singular relationships or relationship counts to expand. Use a show tool for to-many records.'),
            'per_page' => $schema->integer()->description('Results per page (default 15, max 25).')->default(15),
            'page' => $schema->integer()->description('Page number (max 1,000,000).')->default(1),
        ];
```

- Validation drops `search`, `created_after`, `created_before`, `creation_source` and `filter.*`, keeps `'filter' => ['sometimes', $this->objectRule(allowEmpty: true)]`.
- `buildHttpRequest()` passes the tree through:

```php
        $filter = $mcpRequest->get('filter');

        if (is_array($filter) && $filter !== []) {
            $input['filter'] = $filter;
        }
```

- Each MCP list tool's `#[Description]` says `with optional filters and pagination`.

Chat `packages/Chat/src/Tools/BaseReadListTool.php`:
- Delete `searchFilterName()`, `additionalSchema()`, `additionalFilters()`, `creationSourceFilter()` and the overrides in the five chat list tools. Declare `abstract protected function entity(): CrmEntity;` on `BaseReadListTool` (the concrete tools already implement it), then every remaining `$this->searchFilterName()` becomes `$this->entity()->titleColumn()`.
- In `CustomFieldsFilterDescriber::describe()`, the first line becomes `Custom field conditions go under custom_fields. Their keys MUST be one of the codes below; each value is an object of operator => operand.` and the example becomes `{"custom_fields": {"<first code>": {"$eq": "..."}}}`, so the interim description matches the tree until Task 15 replaces it.
- In `schema()`, replace `search`, `created_after`, `created_before`, `creation_source` and `custom_fields` with one `'filter' => $schema->object()->description($filterDescription)`, where `$filterDescription` is `EntityFilters::GRAMMAR."\n\n".$describer->describe($user, $entityType)` when a user is present, else `EntityFilters::GRAMMAR`.
- `buildHttpRequest()`:

```php
        $filter = $request['filter'] ?? null;

        if (is_array($filter) && $filter !== []) {
            $input['filter'] = $filter;
        }
```

- Each chat list tool's `description()` says `with optional filters and pagination`.

Scribe `GetFromSpatieQueryBuilder::extractQueryParams()`: replace `extractFilters()` and `extractCustomFieldFilter()` with one method reading the registry (map the action to its entity: `[ListCompanies::class => CrmEntity::Company, ListPeople::class => CrmEntity::People, ListOpportunities::class => CrmEntity::Opportunity, ListTasks::class => CrmEntity::Task, ListNotes::class => CrmEntity::Note]`):

```php
    /**
     * @param  array<string, array<string, mixed>>  $params
     */
    private function addFilters(CrmEntity $entity, array &$params): void
    {
        foreach (EntityFilters::definitions($entity) as $name => $definition) {
            $params["filter[{$name}][{operator}]"] = [
                'type' => 'string',
                'required' => false,
                'description' => "Filter by {$name}. Operators: ".implode(', ', $definition->operators()).'.',
                'example' => null,
            ];
        }

        $params['filter[custom_fields][{code}][{operator}]'] = [
            'type' => 'string',
            'required' => false,
            'description' => self::CUSTOM_FIELD_FILTER_DESCRIPTION,
            'example' => null,
        ];
    }
```

`lang/en/validation.php`, extend the `filter` group:

```php
    'filter' => [
        'operator_sigil' => 'Operators start with $. Use :operator.',
        'operator_object' => ':name takes an operator object, for example {":operator": ...}.',
        'unsupported_operator' => ':name does not support :operator. Use :supported.',
        'operand_type' => ':name must be :expected.',
        'enum_value' => ':value is not one of: :values.',
        'too_many_values' => ':name takes at most :max values.',
        'members_ids_only' => ':name takes $in, $not_in or $is_empty.',
        'stale_days' => 'stale_days takes {"$gte": <whole days>}.',
        'assigned_to_me' => 'assigned_to_me takes {"$eq": true}.',
    ],
```

Tests using removed params: migrate each to the tree with this table, then fix the assertions.

| Old | New |
|---|---|
| `filter[name]=X`, MCP/chat `search: X` | `filter[name][$contains]=X` / `['name' => ['$contains' => 'X']]` (`title` for tasks and notes) |
| `created_after: D` / `created_before: D` | `['created_at' => ['$gte' => D]]` / `['$lte' => D]` |
| `creation_source: S` | `['creation_source' => ['$eq' => S]]` |
| `company_id: X` | `['company' => ['$in' => [X]]]` (tasks: `companies`) |
| `contact_id: X` | `['contact' => ['$in' => [X]]]` |
| `people_id: X` / `opportunity_id: X` | `['people' => ['$in' => [X]]]` / `['opportunities' => ['$in' => [X]]]` |
| `assignee_ids: [..]` | `['assignees' => ['$in' => [..]]]` |
| `assigned_to_me: true` | `['assigned_to_me' => ['$eq' => true]]` |
| `stale_days: N` | `['stale_days' => ['$gte' => N]]` |
| `notable_type: T, notable_id: X` | `[<companies|people|opportunities> => ['$in' => [X]]]` |
| chat `custom_fields: {...}` (list tools) | `filter: ['custom_fields' => {...}]` |
| MCP `filter: {code: {...}}` | `filter: ['custom_fields' => [code => {...}]]` |

Find them:

```bash
grep -rln -E "filter\[(name|title|company_id|contact_id|created_after|created_before|creation_source)\]" tests
grep -rln -E "'(search|created_after|created_before|creation_source|company_id|contact_id|people_id|opportunity_id|assignee_ids|assigned_to_me|stale_days|notable_type|notable_id)' =>" tests/Feature/Mcp tests/Feature/Chat tests/Feature/CRM
```

Only list-tool and list-endpoint calls change; write-tool payloads (`company_id`, `assignee_ids` on create and update) stay. `tests/Feature/Mcp/Filters/CustomFieldFilterTest.php` builds `QueryBuilder` with `CustomFieldFilter::allowedFilter()`; replace that with `...new EntityFilters($this->user)->for(CrmEntity::Opportunity)` (or `People`), wrap the request filter in `custom_fields` as before, and expect `filter.custom_fields...` keys instead of `filter` in `->throws()` messages by asserting through `ValidationException::errors()`.

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact tests/Feature/Api tests/Feature/Mcp tests/Feature/Chat tests/Feature/CRM tests/Feature/Documentation tests/Arch`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app packages lang tests
git commit -m "feat: filter native fields and relation ids through one filter tree"
```

### Task 11: Logic and nested relation nodes

**Files:**
- Create: `app/Support/Filters/AppliesFilterNodes.php` (trait), `app/Support/Filters/LogicFilter.php`
- Modify: `app/Support/Filters/RelationFilter.php`, `app/Support/Filters/EntityFilters.php`
- Test: `tests/Feature/Api/V1/ListFilterTest.php`

**Interfaces:**
- Consumes: `EntityFilters::for()`, `TreeAllowedFilter`, `FilterErrors`.
- Produces: `LogicFilter::KEYWORDS = ['$and', '$or', '$not']`; `new LogicFilter(string $keyword, CrmEntity $entity, EntityFilters $filters)`; `new RelationFilter(FilterDefinition $definition, EntityFilters $filters)`.

- [ ] **Step 1: Write the failing tests**

```php
it('combines two $or groups with $and', function (): void {
    $stage = CustomField::query()->where('entity_type', 'opportunity')->where('code', 'stage')->firstOrFail();
    $amount = CustomField::query()->where('entity_type', 'opportunity')->where('code', 'amount')->firstOrFail();
    $proposalBig = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Big']);
    $proposalSmall = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Small']);
    $proposalBig->saveCustomFieldValue($stage, (string) $stage->options()->first()->id);
    $proposalBig->saveCustomFieldValue($amount, 90000);
    $proposalSmall->saveCustomFieldValue($stage, (string) $stage->options()->first()->id);
    $proposalSmall->saveCustomFieldValue($amount, 10);
    $label = (string) $stage->options()->first()->name;

    expect(listIds($this, 'opportunities', ['$and' => [
        ['$or' => [['custom_fields' => ['stage' => ['$in' => [$label]]]], ['name' => ['$eq' => 'nothing']]]],
        ['$or' => [['custom_fields' => ['amount' => ['$gt' => 50000]]], ['name' => ['$eq' => 'nothing']]]],
    ]]))->toBe([$proposalBig->id]);
});

it('returns records with an empty value under $not', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['contact_id' => People::factory()->recycle([$this->user, $this->workspace])->create()->id, 'company_id' => $company->id]);
    $noContact = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['contact_id' => null]);

    expect(listIds($this, 'opportunities', ['$not' => ['contact' => ['$is_empty' => false]]]))->toBe([$noContact->id]);
});

it('returns companies without people under $not of a to-many relation', function (): void {
    $withCto = Company::factory()->recycle([$this->user, $this->workspace])->create();
    People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $withCto->id, 'name' => 'Cleo']);
    $empty = Company::factory()->recycle([$this->user, $this->workspace])->create();

    expect(listIds($this, 'companies', ['$not' => ['people' => ['name' => ['$eq' => 'Cleo']]]]))->toBe([$empty->id]);
});

it('applies every condition in a relation node to the same related record', function (): void {
    $jobTitle = CustomField::query()->where('entity_type', 'people')->where('code', 'job_title')->firstOrFail();
    $match = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $split = Company::factory()->recycle([$this->user, $this->workspace])->create();
    People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $match->id, 'name' => 'Berlin CTO'])->saveCustomFieldValue($jobTitle, 'CTO');
    People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $split->id, 'name' => 'Berlin Sales'])->saveCustomFieldValue($jobTitle, 'Sales');
    People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $split->id, 'name' => 'Paris CTO'])->saveCustomFieldValue($jobTitle, 'CTO');

    expect(listIds($this, 'companies', ['people' => [
        'name' => ['$contains' => 'Berlin'],
        'custom_fields' => ['job_title' => ['$eq' => 'CTO']],
    ]]))->toBe([$match->id]);
});

it('follows two relation hops', function (): void {
    $icp = CustomField::query()->where('entity_type', 'company')->where('code', 'icp')->firstOrFail();
    $icpCompany = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $icpCompany->saveCustomFieldValue($icp, true);
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $icpCompany->id]);
    People::factory()->recycle([$this->user, $this->workspace])->create();
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $icpCompany->id, 'name' => 'Deal']);

    expect(listIds($this, 'people', ['company' => [
        'custom_fields' => ['icp' => ['$eq' => true]],
        'opportunities' => ['name' => ['$eq' => 'Deal']],
    ]]))->toBe([$person->id]);
});

it('ignores a soft-deleted related record', function (): void {
    $icp = CustomField::query()->where('entity_type', 'company')->where('code', 'icp')->firstOrFail();
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $company->saveCustomFieldValue($icp, true);
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $company->id]);
    $company->delete();

    expect(listIds($this, 'opportunities', ['company' => ['custom_fields' => ['icp' => ['$eq' => true]]]]))->toBe([])
        ->and(listIds($this, 'opportunities', ['company' => ['$in' => [$company->id]]]))->toBe([]);
});
```

Add `use App\Models\CustomField;` to the file's imports.

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/Api/V1/ListFilterTest.php`
Expected: the new tests FAIL (`$and`, `$or`, `$not` unknown; nested relation keys rejected).

- [ ] **Step 3: Implement**

`AppliesFilterNodes.php` (a trait: `ArchTest` bans abstract classes and inheritance in `App\Support`):

```php
<?php

declare(strict_types=1);

namespace App\Support\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\AllowedFilter;

trait AppliesFilterNodes
{
    /**
     * @param  Builder<Model>  $query
     * @param  list<AllowedFilter>  $registry
     * @param  array<array-key, mixed>  $node
     */
    protected function applyNode(Builder $query, array $registry, array $node): void
    {
        if ($node === [] || array_is_list($node)) {
            throw FilterErrors::at('', __('validation.filter.node_object'));
        }

        foreach ($node as $name => $value) {
            $filter = array_find($registry, static fn (AllowedFilter $candidate): bool => $candidate->getName() === (string) $name);

            if (! $filter instanceof AllowedFilter) {
                throw FilterErrors::at((string) $name, __('validation.filter.unknown_name', [
                    'name' => $name,
                    'available' => implode(', ', array_map(static fn (AllowedFilter $candidate): string => $candidate->getName(), $registry)),
                ]));
            }

            $filter->applyTo($query, $value);
        }
    }
}
```

`LogicFilter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Enums\CrmEntity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * @implements Filter<Model>
 */
final readonly class LogicFilter implements Filter
{
    use AppliesFilterNodes;

    public const array KEYWORDS = ['$and', '$or', '$not'];

    public function __construct(
        private string $keyword,
        private CrmEntity $entity,
        private EntityFilters $filters,
        private User $user,
    ) {}

    /**
     * @param  Builder<Model>  $query
     */
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $registry = $this->filters->for($this->entity);

        if ($this->keyword === '$not') {
            $this->complement($query, $registry, $value);

            return;
        }

        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            throw FilterErrors::at('', __('validation.filter.logic_list', ['keyword' => $this->keyword]));
        }

        $boolean = $this->keyword === '$or' ? 'or' : 'and';

        $query->where(function (Builder $group) use ($registry, $value, $boolean): void {
            foreach ($value as $index => $node) {
                $group->where(function (Builder $branch) use ($registry, $node, $index): void {
                    try {
                        $this->applyNode($branch, $registry, is_array($node) ? $node : []);
                    } catch (ValidationException $exception) {
                        throw FilterErrors::prefix($exception, $index);
                    }
                }, boolean: $boolean);
            }
        });
    }

    /**
     * @param  Builder<Model>  $query
     * @param  list<AllowedFilter>  $registry
     */
    private function complement(Builder $query, array $registry, mixed $node): void
    {
        $model = $query->getModel();
        $matching = $model->newQuery()
            ->select($model->getQualifiedKeyName())
            ->whereBelongsTo($this->user->currentWorkspace);

        $this->applyNode($matching, $registry, is_array($node) ? $node : []);

        $query->whereNotIn($model->getQualifiedKeyName(), $matching);
    }
}
```

`RelationFilter.php` uses the trait (keep its `@implements Filter<Model>` docblock):

```php
final readonly class RelationFilter implements Filter
{
    use AppliesFilterNodes;

    public function __construct(
        private FilterDefinition $definition,
        private EntityFilters $filters,
    ) {}

    /**
     * @param  Builder<Model>  $query
     */
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        if (! is_array($value) || $value === [] || array_is_list($value)) {
            throw FilterErrors::at('', __('validation.filter.operator_object', ['name' => $property, 'operator' => '$in']));
        }

        $linkOperators = array_flip(FilterDefinition::LINK_OPERATORS);

        foreach (array_intersect_key($value, $linkOperators) as $operator => $operand) {
            match ((string) $operator) {
                '$in' => $query->whereHas($property, fn (Builder $related): Builder => $related->whereKey($this->ids('$in', $operand))),
                '$not_in' => $query->whereDoesntHave($property, fn (Builder $related): Builder => $related->whereKey($this->ids('$not_in', $operand))),
                default => $this->emptiness($query, $property, $operand),
            };
        }

        $nested = array_diff_key($value, $linkOperators);

        if ($nested === []) {
            return;
        }

        if (! $this->definition->related instanceof CrmEntity) {
            throw FilterErrors::at((string) array_key_first($nested), __('validation.filter.members_ids_only', ['name' => $property]));
        }

        $registry = $this->filters->for($this->definition->related);

        $query->whereHas($property, fn (Builder $related) => $this->applyNode($related, $registry, $nested));
    }
```

Keep `ids()` and `emptiness()` from Task 10. Import `App\Enums\CrmEntity`. The complement's inner query is bounded to the workspace (`whereBelongsTo`, the same relation every list action already filters on), so `$not` never scans other workspaces' rows.

`EntityFilters::for()`:

```php
                FilterKind::Members, FilterKind::Relation => new RelationFilter($definition, $this),
```

and after the `custom_fields` filter:

```php
        foreach (LogicFilter::KEYWORDS as $keyword) {
            $filters[] = TreeAllowedFilter::custom($keyword, new LogicFilter($keyword, $entity, $this, $this->user));
        }
```

`lang/en/validation.php` `filter` group adds:

```php
        'node_object' => 'Each condition must be an object of field conditions.',
        'logic_list' => ':keyword takes a non-empty list of condition objects.',
        'unknown_name' => 'Unknown filter :name. Use one of: :available.',
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact tests/Feature/Api/V1/ListFilterTest.php tests/Arch`
Expected: PASS. `ConventionsTest` stays green because `applyNode()` is protected and every public builder method implements `Filter`; `ArchTest` stays green because the shared code is a trait.

- [ ] **Step 5: Commit**

```bash
git add app lang tests
git commit -m "feat: combine filters with \$and, \$or, \$not and nested relations"
```

### Task 12: Pre-pass: limits, names and replaced params

**Files:**
- Create: `app/Support/Filters/FilterTree.php`
- Modify: the five list actions (call `FilterTree::validate()` first), `app/Support/Filters/CustomFieldFilter.php` (drop `MAX_CONDITIONS`), `lang/en/validation.php`
- Test: `tests/Feature/Api/V1/ListFilterTest.php`

**Interfaces:**
- Produces: `FilterTree::validate(mixed $filter, CrmEntity $entity): void` (static, arrays only), throwing `filter...`-keyed `ValidationException`s.

- [ ] **Step 1: Write the failing tests**

```php
it('rejects a filter that is not an object', function (): void {
    $this->getJson('/api/v1/companies?filter=acme')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter']);
});

it('names the replacement of a removed param', function (string $param, string $replacement): void {
    $this->getJson("/api/v1/opportunities?filter[{$param}]=x")
        ->assertUnprocessable()
        ->assertJsonFragment(["{$param} was replaced. Use {$replacement}."]);
})->with([
    ['created_after', 'created_at with $gte'],
    ['company_id', 'company (or companies) with $in'],
    ['search', 'name or title with $contains'],
]);

it('caps a filter at twenty conditions', function (): void {
    $conditions = array_fill(0, 21, ['name' => ['$eq' => 'x']]);

    $this->postJson('/api/v1/companies/query', ['filter' => ['$or' => $conditions]])
        ->assertUnprocessable()
        ->assertJsonFragment(['A filter holds at most 20 conditions. This one has 21.']);
})->skip('enabled in Task 14, when POST /query exists');

it('caps logic depth at three and relation hops at two', function (): void {
    $deep = ['$not' => ['$or' => [['$and' => [['$not' => ['name' => ['$eq' => 'x']]]]]]]];
    $far = ['company' => ['people' => ['company' => ['name' => ['$eq' => 'x']]]]];

    $this->getJson('/api/v1/companies?'.http_build_query(['filter' => $deep]))->assertUnprocessable()->assertJsonValidationErrors(['filter']);
    $this->getJson('/api/v1/opportunities?'.http_build_query(['filter' => $far]))->assertUnprocessable()->assertJsonValidationErrors(['filter.company.people.company']);
});

it('rejects an empty $or and an empty relation node', function (): void {
    $this->getJson('/api/v1/companies?filter[$or]=')->assertUnprocessable();

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['$or' => [['company' => []]]]])
        ->assertHasErrors(['filter.$or.0.company needs at least one condition.']);
});
```

Import `App\Mcp\Servers\RelaticleServer` and `App\Mcp\Tools\Opportunity\ListOpportunitiesTool` (`http_build_query` drops empty arrays, so the empty relation node travels as MCP JSON). If laravel/mcp renders the error without its key, assert on the message alone. The 20-condition case moves to Task 14 because 21 conditions do not fit a GET line comfortably; remove the `skip` there.

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/Api/V1/ListFilterTest.php --filter="not an object|removed param|depth|empty"`
Expected: FAIL (Spatie ignores the string filter and returns 400 for unknown names).

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Enums\CrmEntity;

final readonly class FilterTree
{
    public const int MAX_CONDITIONS = 20;

    public const int MAX_LOGIC_DEPTH = 3;

    public const int MAX_HOPS = 2;

    private const array REPLACED = [
        'search' => 'name or title with $contains',
        'created_after' => 'created_at with $gte',
        'created_before' => 'created_at with $lte',
        'company_id' => 'company (or companies) with $in',
        'contact_id' => 'contact with $in',
        'people_id' => 'people with $in',
        'opportunity_id' => 'opportunities with $in',
        'assignee_ids' => 'assignees with $in',
        'notable_type' => 'companies, people or opportunities with $in',
        'notable_id' => 'companies, people or opportunities with $in',
    ];

    public static function validate(mixed $filter, CrmEntity $entity): void
    {
        if ($filter === null || $filter === '' || $filter === []) {
            return;
        }

        if (! is_array($filter) || array_is_list($filter)) {
            throw FilterErrors::at('filter', __('validation.filter.not_object'));
        }

        $conditions = self::walk($filter, $entity, 'filter', 0, 0);

        if ($conditions > self::MAX_CONDITIONS) {
            throw FilterErrors::at('filter', __('validation.filter.too_many_conditions', ['max' => self::MAX_CONDITIONS, 'count' => $conditions]));
        }
    }

    /**
     * @param  array<array-key, mixed>  $node
     */
    private static function walk(array $node, CrmEntity $entity, string $path, int $depth, int $hops): int
    {
        if ($node === []) {
            throw FilterErrors::at($path, __('validation.filter.empty_node', ['name' => $path]));
        }

        $definitions = EntityFilters::definitions($entity);
        $conditions = 0;

        foreach ($node as $name => $value) {
            $name = (string) $name;
            $child = "{$path}.{$name}";

            if (in_array($name, LogicFilter::KEYWORDS, true)) {
                if ($depth + 1 > self::MAX_LOGIC_DEPTH) {
                    throw FilterErrors::at('filter', __('validation.filter.too_deep', ['max' => self::MAX_LOGIC_DEPTH]));
                }

                $branches = $name === '$not' ? [$value] : (is_array($value) && array_is_list($value) && $value !== [] ? $value : null);

                if ($branches === null) {
                    throw FilterErrors::at($child, __('validation.filter.logic_list', ['keyword' => $name]));
                }

                foreach ($branches as $index => $branch) {
                    $conditions += self::walk(is_array($branch) ? $branch : [], $entity, $name === '$not' ? $child : "{$child}.{$index}", $depth + 1, $hops);
                }

                continue;
            }

            if (isset(self::REPLACED[$name]) && $path === 'filter') {
                throw FilterErrors::at($child, __('validation.filter.replaced', ['name' => $name, 'replacement' => self::REPLACED[$name]]));
            }

            if ($name === 'custom_fields') {
                $conditions += self::countOperators($value);

                continue;
            }

            $definition = $definitions[$name] ?? throw FilterErrors::at($child, __('validation.filter.unknown_name', [
                'name' => $name,
                'available' => implode(', ', [...array_keys($definitions), 'custom_fields', ...LogicFilter::KEYWORDS]),
            ]));

            if ($definition->kind !== FilterKind::Relation) {
                $conditions += self::countOperators($value);

                continue;
            }

            if ($hops + 1 > self::MAX_HOPS) {
                throw FilterErrors::at($child, __('validation.filter.too_many_hops', ['max' => self::MAX_HOPS]));
            }

            $value = is_array($value) ? $value : [];
            $conditions += count(array_intersect_key($value, array_flip(FilterDefinition::LINK_OPERATORS)));
            $nested = array_diff_key($value, array_flip(FilterDefinition::LINK_OPERATORS));

            if ($value === [] || ($nested !== [] && $definition->related instanceof CrmEntity)) {
                $conditions += self::walk($nested === [] ? $value : $nested, $definition->related ?? $entity, $child, $depth, $hops + 1);
            }
        }

        return $conditions;
    }

    private static function countOperators(mixed $value): int
    {
        if (! is_array($value)) {
            return 1;
        }

        $count = 0;

        foreach ($value as $key => $child) {
            $count += str_starts_with((string) $key, '$') ? 1 : self::countOperators($child);
        }

        return $count;
    }
}
```

Each list action calls it right after `abort_unless(...)` and the `$request ??=` line:

```php
        FilterTree::validate($request->input('filter'), CrmEntity::Task);
```

`CustomFieldFilter`: delete `MAX_CONDITIONS` and its check (the tree counts every condition now); delete the `validation.custom_field.too_many_conditions` key. In `GetFromSpatieQueryBuilder::CUSTOM_FIELD_FILTER_DESCRIPTION`, replace `Up to 10 conditions, 100 values per list.` with `Up to 20 conditions per filter, 100 values per list.`

`lang/en/validation.php` `filter` group adds:

```php
        'not_object' => 'The filter must be an object.',
        'empty_node' => ':name needs at least one condition.',
        'replaced' => ':name was replaced. Use :replacement.',
        'too_many_conditions' => 'A filter holds at most :max conditions. This one has :count.',
        'too_deep' => '$and, $or and $not nest at most :max levels.',
        'too_many_hops' => 'Relations nest at most :max levels.',
```

MCP `BaseListTool::handle()` already turns a `ValidationException` into a tool error through laravel/mcp; chat's `BaseReadListTool::handle()` already catches it. Confirm both by running their filter tests.

Chat's `BaseReadListTool::buildHttpRequest()` passes `filter` only when it is an array, so a JSON-string filter silently returns every row. Pass any `filled()` value through (`if (filled($filter)) { $input['filter'] = $filter; }`) so `FilterTree::validate()` answers it with the `not_object` 422, and add a test in `tests/Feature/Chat/ListToolFilterTest.php`: `filter: '{"name":"x"}'` returns the error, not a table.

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact tests/Feature/Api/V1/ListFilterTest.php tests/Feature/Mcp tests/Feature/Chat/ListToolFilterTest.php`
Expected: PASS (the 20-condition case stays skipped until Task 14).

- [ ] **Step 5: Commit**

```bash
git add app lang tests
git commit -m "feat: validate filter trees before building the query"
```

### Task 13: Matching emails, links and phones

**Files:**
- Modify: `app/Support/Filters/CustomFieldFilter.php`, `app/Mcp/Schema/CustomFieldFilterSchema.php`, `app/Support/Filters/FilterTree.php` (sub-field operators count), `lang/en/validation.php`
- Test: `tests/Feature/Mcp/Filters/CustomFieldFilterTest.php`

**Interfaces:**
- Produces: a `domain` sub-field on email and link fields (`{"emails": {"domain": {"$in": ["acme.com"]}}}`), case-insensitive `$has_any`/`$has_none` on email and link, operands normalized through the field type before comparing (phones become E.164).

- [ ] **Step 1: Write the failing tests**

```php
it('matches an email in any case and by domain', function (): void {
    $emails = filterTestField($this->workspace, 'people', 'work_emails', 'email', new CustomFieldSettingsData(allow_multiple: true, max_values: 5));
    $ana = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Ana']);
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Bob'])->saveCustomFieldValue($emails, ['bob@globex.com']);
    $ana->saveCustomFieldValue($emails, ['Ana.Smith@Acme.com']);

    expect(peopleNamesMatching($this->user, ['custom_fields' => ['work_emails' => ['$has_any' => ['ana.smith@acme.com']]]]))->toBe(['Ana'])
        ->and(peopleNamesMatching($this->user, ['custom_fields' => ['work_emails' => ['domain' => ['$in' => ['ACME.com']]]]]))->toBe(['Ana'])
        ->and(peopleNamesMatching($this->user, ['custom_fields' => ['work_emails' => ['domain' => ['$not_in' => ['acme.com']]]]]))->toBe(['Bob']);
});

it('matches a phone written in another format', function (): void {
    $phone = filterTestField($this->workspace, 'people', 'mobile', 'phone', new CustomFieldSettingsData(allow_multiple: true, max_values: 5));
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Ana'])->saveCustomFieldValue($phone, ['+1 (415) 555-0100']);

    expect(peopleNamesMatching($this->user, ['custom_fields' => ['mobile' => ['$has_any' => ['+1 415 555 0100']]]]))->toBe(['Ana']);
});

it('matches a url-variant link by its host', function (): void {
    $site = filterTestField($this->workspace, 'people', 'site', 'link', new CustomFieldSettingsData(allow_multiple: true, max_values: 5));
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Ana'])->saveCustomFieldValue($site, ['https://www.Acme.com/team']);

    expect(peopleNamesMatching($this->user, ['custom_fields' => ['site' => ['domain' => ['$in' => ['acme.com']]]]]))->toBe(['Ana']);
});

it('finds a stored link by a raw url operand', function (): void {
    $site = filterTestField($this->workspace, 'people', 'homepage', 'link', new CustomFieldSettingsData(allow_multiple: true, max_values: 5));
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Ana'])->saveCustomFieldValue($site, ['https://acme.com/team, hiring']);

    expect(peopleNamesMatching($this->user, ['custom_fields' => ['homepage' => ['$has_any' => ['HTTPS://acme.com/team, hiring']]]]))->toBe(['Ana']);
});

it('publishes only $ operators and the domain sub-field', function (): void {
    $keys = collect(App\Enums\CustomFieldType::cases())
        ->flatMap(fn (App\Enums\CustomFieldType $type): array => array_keys(CustomFieldFilterSchema::operatorsForType($type->value)))
        ->unique()
        ->reject(fn (string $key): bool => str_starts_with($key, '$'))
        ->values()
        ->all();

    expect($keys)->toBe(['domain']);
});

it('asks for a country code on a national phone operand', function (): void {
    $phone = filterTestField($this->workspace, 'people', 'mobile', 'phone', new CustomFieldSettingsData(allow_multiple: true, max_values: 5));

    expect(fn () => peopleNamesMatching($this->user, ['custom_fields' => ['mobile' => ['$has_any' => ['415 555 0100']]]]))
        ->toThrow(ValidationException::class, 'mobile needs a country code');
});

it('offers the domain sub-field only on email and link fields', function (): void {
    expect(fn () => peopleNamesMatching($this->user, ['custom_fields' => ['job_title' => ['domain' => ['$in' => ['x']]]]]))
        ->toThrow(ValidationException::class);
});
```

Add the file-local helper next to the others:

```php
/** @return list<string> */
function peopleNamesMatching(User $user, array $filter): array
{
    return QueryBuilder::for(People::query()->withCustomFieldValues(), new Request(['filter' => $filter]))
        ->allowedFilters(...new EntityFilters($user)->for(CrmEntity::People))
        ->pluck('name')
        ->sort()
        ->values()
        ->all();
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/Mcp/Filters/CustomFieldFilterTest.php --filter="any case|another format|by its host|only on email"`
Expected: FAIL.

- [ ] **Step 3: Implement**

`CustomFieldFilterSchema::operatorsForType()`: email and link get a `domain` entry, published with the operators:

```php
            CustomFieldType::EMAIL, CustomFieldType::LINK => [
                ...self::listOperators(['$has_any', '$has_none']),
                'domain' => ['type' => 'object', 'properties' => self::listOperators(['$in', '$not_in'])],
            ],
            CustomFieldType::PHONE,
            CustomFieldType::MULTI_SELECT, CustomFieldType::CHECKBOX_LIST, CustomFieldType::TAGS_INPUT => self::listOperators(['$has_any', '$has_none']),
```

`CustomFieldFilter::__invoke()`, inside the operator loop, route the sub-field before the operator checks:

```php
                if ($operator === 'domain') {
                    $this->applyDomain($query, $field, $operand);

                    continue;
                }
```

and normalize free-text list operands through the field type (after `normalizeOperand`, before `applyCondition`):

```php
                $operand = $this->canonical($field, $operand);
```

Add:

```php
    private const array DOMAIN_OF = [
        'email' => "lower(split_part(element, '@', 2))",
        'link' => "rtrim(regexp_replace(split_part(regexp_replace(split_part(split_part(split_part(regexp_replace(regexp_replace(lower(element), '\\s+', '', 'g'), '^[a-z][a-z0-9+.-]*://', ''), '/', 1), '?', 1), '#', 1), '^.*@', ''), ':', 1), '^(www\\.)+', ''), '.')",
    ];

    private function canonical(CustomField $field, mixed $operand): mixed
    {
        if (! is_array($operand) || ! in_array($field->type, ['email', 'link', 'phone'], true)) {
            return $operand;
        }

        $definition = CustomFieldsType::getFieldTypeInstance($field->type);

        return array_map(function (string $value) use ($field, $definition): string {
            if ($field->type === 'email') {
                return mb_strtolower(trim($value));
            }

            $normalized = $definition instanceof BaseFieldType ? $definition->normalize($value, $field) : $value;

            if ($field->type === 'phone' && ! str_starts_with($normalized, '+')) {
                throw FilterErrors::at($field->code, __('validation.filter.phone_country_code', ['name' => $field->code]));
            }

            return $normalized;
        }, $operand);
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyDomain(Builder $query, CustomField $field, mixed $operators): void
    {
        $expression = self::DOMAIN_OF[$field->type] ?? throw FilterErrors::at("{$field->code}.domain", __('validation.filter.sub_field', ['sub_field' => 'domain']));

        if (! is_array($operators) || $operators === [] || array_is_list($operators)) {
            throw FilterErrors::at("{$field->code}.domain", __('validation.filter.operator_object', ['name' => 'domain', 'operator' => '$in']));
        }

        foreach ($operators as $operator => $operand) {
            $domains = Operand::listOrFail($operand, splitsStrings: true, path: "{$field->code}.domain.{$operator}", expected: 'a list of domains such as acme.com');
            $domains = array_map(static fn (string $domain): string => mb_strtolower(trim($domain)), $domains);

            if (array_any($domains, static fn (string $domain): bool => preg_match('/^[a-z0-9.-]+$/', $domain) !== 1)) {
                throw FilterErrors::at("{$field->code}.domain.{$operator}", __('validation.filter.operand_type', ['name' => (string) $operator, 'expected' => 'a list of domains such as acme.com']));
            }

            $matching = fn (Builder $values): Builder => $values
                ->where('custom_field_id', $field->getKey())
                ->whereRaw("exists (select 1 from jsonb_array_elements_text(custom_field_values.json_value::jsonb) as element where {$expression} = any(?::text[]))", ['{'.implode(',', $domains).'}']);

            match ((string) $operator) {
                '$in' => $query->whereHas('customFieldValues', $matching),
                '$not_in' => $query->whereDoesntHave('customFieldValues', $matching),
                default => throw FilterErrors::at("{$field->code}.domain.{$operator}", __('validation.filter.unsupported_operator', ['name' => 'domain', 'operator' => $operator, 'supported' => '$in, $not_in'])),
            };
        }
    }
```

The host check above keeps `,`, `{`, `}` and `"` out of the Postgres array literal. The `link` expression mirrors `LinkFieldType::normalize()` from custom-fields 3.12 step for step (lowercase, drop whitespace, strip scheme, cut at `/`, `?`, `#`, strip userinfo, cut at `:`, strip leading `www.`, trim trailing dots), so a stored URL and a domain-variant value yield the same host; add a test row `https://acme.com?ref=a@b.com` that must match `acme.com`. Imports for `CustomFieldFilter`: `Relaticle\CustomFields\Facades\CustomFieldsType`, `Relaticle\CustomFields\FieldTypeSystem\BaseFieldType`.

`containsAny()` becomes case-insensitive for email and link:

```php
    private function containsAny(Builder $query, CustomField $field, string $valueColumn, array $values): void
    {
        if (in_array($field->type, ['email', 'link'], true)) {
            $query->whereRaw(
                "exists (select 1 from jsonb_array_elements_text({$valueColumn}::jsonb) as element where lower(element) = any(?::text[]))",
                ['{'.implode(',', array_map(static fn (string $value): string => '"'.addcslashes(mb_strtolower($value), '"\\').'"', $values)).'}'],
            );

            return;
        }

        $query->where(function (Builder $anyValue) use ($valueColumn, $values): void {
            foreach ($values as $value) {
                $anyValue->orWhereJsonContains($valueColumn, [$value]);
            }
        });
    }
```

Update both `containsAny()` call sites to pass `$field`.

`FilterTree::countOperators()` already counts the `$in` inside `domain` (bare key recurses).

`lang/en/validation.php` `filter` group adds:

```php
        'sub_field' => ':sub_field is available on email and link fields.',
        'phone_country_code' => ':name needs a country code, for example +1 415 555 0100.',
```

In `GetFromSpatieQueryBuilder::CUSTOM_FIELD_FILTER_DESCRIPTION`, replace `Tags, email, phone and link values match the exact stored value, so repeat [] to send several.` with `Tags match the exact stored value. Email and link values match in any case and phones in any format; email and link take a domain sub-field, for example filter[custom_fields][emails][domain][$in]=acme.com. Repeat [] to send several values.`

- [ ] **Step 3b: Search finds a phone typed in any format**

After the backfill, stored phones are `+14155550100`, so a search for `415-555-0100` in chat's `SearchCrmTool` and MCP's `SearchTool` stops matching. In both, when the query holds 7 or more digits, also match phone-type values on their digits: add `or regexp_replace(<element>, '\D', '', 'g') like ?` with the binding `'%'.preg_replace('/\D/', '', $query).'%'` next to the existing ILIKE on json array elements, scoped to fields of type `phone`. Read both tools' search SQL first and add the clause where they already expand `json_value` elements. Test in `tests/Feature/Chat/SearchCrmToolTest.php`: a person stored with `+14155550100` is found by `415-555-0100` and by `(415) 555 0100`.

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact tests/Feature/Mcp/Filters/CustomFieldFilterTest.php tests/Feature/Api/V1/ListFilterTest.php tests/Feature/Chat/SearchCrmToolTest.php tests/Feature/Mcp`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app packages lang tests
git commit -m "feat: match emails, links and phones in any format"
```

### Task 14: POST /v1/{entity}/query

**Files:**
- Modify: `routes/api.php`, `app/Http/Middleware/EnsureTokenHasAbility.php`
- Create: `app/Scribe/Strategies/GetFilterBodyFromEntityFilters.php`
- Modify: `app/Scribe/Strategies/GetFromSpatieQueryBuilder.php`, `config/scribe.php`
- Test: `tests/Feature/Api/V1/ListFilterTest.php`, `tests/Feature/Documentation/ApiDocumentationGenerationTest.php`

- [ ] **Step 1: Write the failing tests**

In `ListFilterTest.php`, remove the `->skip(...)` from the 20-condition test and add:

```php
it('answers a query body with the same records as the query string', function (): void {
    $acme = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme']);
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Globex']);
    $filter = ['$or' => [['name' => ['$eq' => 'Acme']], ['name' => ['$eq' => 'Nope']]]];

    $viaBody = collect($this->postJson('/api/v1/companies/query', ['filter' => $filter, 'per_page' => 5])->assertOk()->json('data'))->pluck('id')->all();

    expect($viaBody)->toBe([$acme->id])->and(listIds($this, 'companies', $filter))->toBe([$acme->id]);
});

it('lets a read-only token query', function (): void {
    auth()->forgetGuards();
    $token = $this->user->createToken('read', ['read'])->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/companies/query', ['filter' => ['name' => ['$contains' => 'a']]])->assertOk();
    $this->withToken($token)->postJson('/api/v1/companies', ['name' => 'Nope'])->assertForbidden();
});
```

In `ApiDocumentationGenerationTest.php`, inside the first test:

```php
    expect($spec['paths']['/api/v1/companies/query']['post']['requestBody']['content']['application/json']['schema']['properties'])
        ->toHaveKeys(['filter', 'sort', 'per_page']);
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/Api/V1/ListFilterTest.php tests/Feature/Documentation`
Expected: FAIL (404 on `/query`).

- [ ] **Step 3: Implement**

`routes/api.php`, before the `apiResource` lines:

```php
        foreach (['companies' => CompaniesController::class, 'people' => PeopleController::class, 'opportunities' => OpportunitiesController::class, 'tasks' => TasksController::class, 'notes' => NotesController::class] as $resource => $controller) {
            Route::post("{$resource}/query", [$controller, 'index'])->name("{$resource}.query");
        }
```

`EnsureTokenHasAbility`: resolve from the route action first:

```php
        foreach ($abilities ?: [$this->resolveAbility($request)] as $ability) {
```

```php
    private function resolveAbility(Request $request): string
    {
        if ($request->route()?->getActionMethod() === 'index') {
            return 'read';
        }

        return match ($request->method()) {
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => 'read',
        };
    }
```

`GetFromSpatieQueryBuilder::__invoke()`: return `null` when `in_array('POST', $endpointData->httpMethods, true)`.

`GetFilterBodyFromEntityFilters` (a `bodyParameters` strategy for the POST `index` routes):

```php
<?php

declare(strict_types=1);

namespace App\Scribe\Strategies;

use App\Support\Filters\EntityFilters;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;

final class GetFilterBodyFromEntityFilters extends Strategy
{
    /**
     * @param  array<string, array<string, string|bool>>  $routeRules
     * @return array<string, array<string, mixed>>|null
     */
    public function __invoke(ExtractedEndpointData $endpointData, array $routeRules = []): ?array
    {
        if ($endpointData->method->getName() !== 'index' || ! in_array('POST', $endpointData->httpMethods, true)) {
            return null;
        }

        return [
            'filter' => ['type' => 'object', 'required' => false, 'description' => EntityFilters::GRAMMAR, 'example' => ['name' => ['$contains' => 'Acme'], '$or' => [['custom_fields' => ['icp' => ['$eq' => true]]]]]],
            'sort' => ['type' => 'string', 'required' => false, 'description' => 'Sort field. Prefix with - for descending.', 'example' => '-created_at'],
            'include' => ['type' => 'string', 'required' => false, 'description' => 'Comma-separated relationships to include.', 'example' => null],
            'per_page' => ['type' => 'integer', 'required' => false, 'description' => 'Results per page (max 100).', 'example' => 15],
            'cursor' => ['type' => 'string', 'required' => false, 'description' => 'Cursor for cursor pagination.', 'example' => null],
            'page' => ['type' => 'integer', 'required' => false, 'description' => 'Page number.', 'example' => 1],
        ];
    }
}
```

`config/scribe.php`: `'bodyParameters' => [...Defaults::BODY_PARAMETERS_STRATEGIES, GetFilterBodyFromEntityFilters::class],`.

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact tests/Feature/Api tests/Feature/Documentation tests/Smoke`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add routes app config tests
git commit -m "feat: accept filter queries as a json body"
```

### Task 15: One published vocabulary

**Files:**
- Create: `app/Support/Filters/FilterVocabulary.php`
- Modify: `app/Mcp/Schema/CustomFieldSchema.php` (`filterableFields()`), `packages/Chat/src/Services/Tools/CustomFieldsFilterDescriber.php`
- Test: `tests/Feature/CRM/SurfaceParityTest.php`, `tests/Feature/Mcp/McpReadToolsTest.php`

**Interfaces:**
- Produces: `FilterVocabulary::for(User $user, CrmEntity $entity): array<string, mixed>`:
  - native and computed names: `['type' => <kind>, 'operators' => [...], 'values' => [...]]` (`values` only for enums)
  - relations: `['type' => 'relation'|'members', 'entity' => <related>, 'operators' => ['$in', '$not_in', '$is_empty']]`
  - `custom_fields`: `[code => ['name' => ..., 'type' => ..., 'operators' => [...], 'options' => [labels...], 'sub_fields' => ['domain' => ['$in', '$not_in']]]]`

- [ ] **Step 1: Write the failing tests**

`SurfaceParityTest.php`:

```php
it('publishes one filter vocabulary on mcp, chat and the registry', function (CrmEntity $entity, string $chatTool): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $vocabulary = resolve(FilterVocabulary::class)->for($user, $entity);
    $registry = array_map(fn (AllowedFilter $filter): string => $filter->getName(), new EntityFilters($user)->for($entity));
    $published = (array) RelaticleServer::actingAs($user)->tool(GetCrmSchemaTool::class, ['entity_type' => $entity->value])->json('filterable_fields');
    $chatDescription = resolve($chatTool)->schema(new JsonSchemaTypeFactory)['filter']->toArray()['description'];

    expect(array_keys($vocabulary))->toEqualCanonicalizing(array_values(array_diff($registry, LogicFilter::KEYWORDS)))
        ->and(array_keys($published))->toEqualCanonicalizing(array_keys($vocabulary))
        ->and(array_diff(array_keys($vocabulary), str($chatDescription)->matchAll('/[A-Za-z_]+/')->all()))->toBe([]);
})->with([
    [CrmEntity::Company, ChatListCompanies::class],
    [CrmEntity::Opportunity, ChatListOpportunities::class],
    [CrmEntity::Task, ChatListTasks::class],
]);
```

Read how the existing `McpReadToolsTest` reads structured tool output (`->assertStructuredContent()` or `->json()`) and copy that call; import the chat list tools with aliases as the file already does for MCP ones.

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact tests/Feature/CRM/SurfaceParityTest.php --filter=vocabulary`
Expected: FAIL (`FilterVocabulary` missing).

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Enums\CrmEntity;
use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\CustomField;
use App\Models\User;
use App\Support\CustomFields\CustomFieldOptionMap;
use App\Support\CustomFields\WorkspaceCustomFields;
use BackedEnum;

final readonly class FilterVocabulary
{
    public function __construct(
        private CustomFieldFilterSchema $filterSchema,
        private WorkspaceCustomFields $customFields,
        private CustomFieldOptionMap $optionMap,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user, CrmEntity $entity): array
    {
        $vocabulary = [];

        foreach (EntityFilters::definitions($entity) as $name => $definition) {
            $entry = ['type' => $definition->kind->value, 'operators' => $definition->operators()];

            if ($definition->related instanceof CrmEntity) {
                $entry['entity'] = $definition->related->value;
            }

            if ($definition->enumClass !== null) {
                $entry['values'] = array_map(static fn (BackedEnum $case): string => (string) $case->value, $definition->enumClass::cases());
            }

            $vocabulary[$name] = $entry;
        }

        $vocabulary['custom_fields'] = $this->customFieldEntries($user, $entity);

        return $vocabulary;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function customFieldEntries(User $user, CrmEntity $entity): array
    {
        $schema = $this->filterSchema->build($user, $entity->value);
        $fields = $this->customFields->forEntity($user->currentWorkspace, $entity->value)->keyBy('code');
        $entries = [];

        foreach ($schema as $code => $definition) {
            $properties = is_array($definition['properties'] ?? null) ? $definition['properties'] : [];
            $field = $fields->get($code);
            $entry = [
                'name' => $definition['description'] ?? $code,
                'type' => $field instanceof CustomField ? $field->type : null,
                'operators' => array_values(array_filter(array_keys($properties), static fn (string $key): bool => str_starts_with($key, '$'))),
            ];

            if (isset($properties['domain'])) {
                $entry['sub_fields'] = ['domain' => ['$in', '$not_in']];
            }

            if ($field instanceof CustomField && $this->optionMap->translates($field)) {
                $entry['options'] = array_values(array_map(strval(...), $field->options->pluck('name')->all()));
            }

            $entries[$code] = $entry;
        }

        return $entries;
    }
}
```

`CustomFieldSchema::filterableFields()` returns `(object) resolve(FilterVocabulary::class)->for($user, $entity)`. Update `CustomFieldSchema::USAGE` to point at it: `... Filter list tools with the "filter" param; names, operators and options are listed in filterable_fields.`

`CustomFieldsFilterDescriber::describe()` renders the whole vocabulary, one line per name, so chat sees native fields and relations too:

```php
    public function describe(User $user, string $entityType): string
    {
        $vocabulary = $this->vocabulary->for($user, CrmEntity::from($entityType));
        $customFields = $vocabulary['custom_fields'];
        unset($vocabulary['custom_fields']);

        $lines = ['Names for this workspace:'];

        foreach ($vocabulary as $name => $entry) {
            $lines[] = "- {$name} ({$entry['type']}; operators: ".implode(', ', $entry['operators']).(isset($entry['values']) ? '; one of: '.implode(', ', $entry['values']) : '').')';
        }

        $lines[] = 'custom_fields codes:';

        foreach ($customFields as $code => $entry) {
            $line = "- {$code} ({$entry['name']}; operators: ".implode(', ', $entry['operators']);
            $line .= isset($entry['sub_fields']) ? '; sub-field domain: $in, $not_in' : '';
            $line .= isset($entry['options']) ? '; one of: "'.implode('", "', $entry['options']).'"' : '';
            $lines[] = $line.')';
        }

        return implode("\n", $lines);
    }
```

Inject `FilterVocabulary $vocabulary` in its constructor; keep `sortableCodes()`; drop the now-unused `optionLabels()`.

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact tests/Feature/CRM tests/Feature/Mcp tests/Feature/Chat`
Expected: PASS. Adjust any existing assertion on the old `filterable_fields` shape (custom fields now sit under `custom_fields`).

- [ ] **Step 5: Commit**

```bash
git add app packages tests
git commit -m "feat: publish one filter vocabulary to mcp and chat"
```

### Task 16: Docs pages

**Files:**
- Modify: `packages/Documentation/resources/content/docs/guides/mcp.md`
- Modify: `packages/Documentation/resources/content/help/getting-started/find-anything-with-search-and-filters.md`
- Test: `tests/Feature/Commands/GenerateSitemapCommandTest.php` (date follow-up only if it fails), docs smoke tests

- [ ] **Step 1:** In `mcp.md`, replace the filter section with the grammar from the spec (decisions 1 to 6) and three examples: an `$or` of stages, a relation node (`company.custom_fields.icp`), and an email `domain` filter. Keep YAML front matter values free of a bare `: `.
- [ ] **Step 2:** In the help article, add a short "Filter through the API, MCP or the assistant" paragraph that names the `filter` object and links to the MCP guide. Plain sentences, 25 words at most, no em-dashes.
- [ ] **Step 3:** Run `php artisan test --compact tests/Feature/Documentation tests/Smoke tests/Arch/ConventionsTest.php`. Expected: PASS (ConventionsTest scans `packages/` for em-dashes).
- [ ] **Step 4:** Commit: `git commit -am "docs: document the filter language for the api and mcp"`

### Task 17: Final verification

- [ ] **Step 1: Query cost.** Run EXPLAIN ANALYZE on production-sized data through the read-only path (`ssh relaticle-prod`, `--set=default_transaction_read_only=on`) for the largest workspace, using the SQL the worst allowed tree builds: capture it with `DB::listen` in a test that runs a 20-condition, 2-hop, depth-3 filter, then substitute that workspace's ids. Expected: under 300 ms. If not, lower `FilterTree::MAX_CONDITIONS` and record the measured numbers in the PR body.
- [ ] **Step 2: Chat on the production-shaped stack.** Horizon running, `QUEUE_CONNECTION=redis`, Reverb up. In a real browser (agent-browser), ask: "open deals in Proposal or Negotiation at ICP companies", then "who do we know at acme.com", then "find +1 415 555 0100". Each answer renders a list block from one list call with a `filter` tree; capture screenshots under `.context/`.
- [ ] **Step 3: MCP.** Through a real MCP client against the local server, run `get-crm-schema` for opportunities and one `list-opportunities-tool` call with an `$or` and a relation node.
- [ ] **Step 4: Gates.** `vendor/bin/pint --test --parallel`, `vendor/bin/rector --dry-run`, `vendor/bin/phpstan analyse`, `composer test:type-coverage`, `composer test:pest:full`, `php artisan test tests/Browser`.
- [ ] **Step 5: Tag the package and pin the release (user checkpoint).** With the user's go-ahead: tag v3.12.0 on `3.x` (`git tag v3.12.0 <3.x sha> && git push origin v3.12.0`; release.yml creates the release and CHANGELOG), append the upgrade notes from PR #244 to the release body, confirm `repo.packagist.org/p2/relaticle/custom-fields.json` lists it, then `composer require "relaticle/custom-fields:^3.12" -W` here and re-run the targeted suites.
- [ ] **Step 6: Release note draft.** For the PR body: the v1 filter shape is replaced; old shapes return 422s naming the replacement; examples for GET and POST. Show the draft to the user before posting anything, and tell the paying API customer before release.
