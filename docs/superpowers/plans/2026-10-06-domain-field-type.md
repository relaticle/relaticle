# Domain Field Type Implementation Plan

> **For agentic workers:** execute this plan with the `sdd-lean` skill, task by task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Company `domains` becomes a `domain` field type that Relaticle owns, and the
`link_variant` setting disappears from both repos.

**Architecture:** `relaticle/custom-fields` 3.14 adds a `systemOnly()` flag that keeps a type
out of the settings picker, and drops the `link_variant` branch from `LinkFieldType`.
Relaticle registers `App\Filament\CustomFields\DomainFieldType`, which holds the host
normalizer, and one migration flips the stored type.

**Tech Stack:** PHP 8.5, Laravel, Filament, Pest, PostgreSQL, `relaticle/custom-fields` 3.x.

**Spec:** `docs/superpowers/specs/2026-10-06-domain-field-type-design.md`

## Global Constraints

- Two repos. Part A runs in `/Users/manuk/Herd/custom-fields` on a branch off `origin/3.x`.
  Part B runs in the Relaticle workspace.
- Part B cannot pass on 3.13. Task B1 points Relaticle at the Part A branch first.
- The package is tagged only after the Relaticle PR passes `finalize-pr` and the real
  integrations walk (Task B8). Task B9 does the tagging.
- No `down()` in migrations. No test loads a migration file. The migration is proven by a
  rehearsal on anonymized production data.
- No comments in tests. No em-dash anywhere. No AI attribution in commits or PR text.
- No new PHPStan ignore. Every parameter and return type is declared.
- Local tests are scoped to the files named in each task. CI is the suite gate.
- User-facing strings go through `__()`.
- `domain` never joins `CreateCustomField::ALLOWED_TYPES`.
- Commits are conventional, lowercase, under 72 characters.

## Review Focus

1. A company whose stored domain is still a legacy spelling (`www.acme.com`,
   `https://acme.com`): a filter, an upsert match and a resubmit must still find it. Pinned
   in Task B2.
2. An operand or a written value that stacks schemes or carries odd whitespace
   (`http:// http://\tacme.com`): it reduces to the host without looping. Pinned in Task B2.
3. A value that is not a host at all (`N/A`, `tel:+1415...`): it is kept as typed, never
   emptied. Pinned in Task B2.
4. Editing the system `domains` field in settings: the form opens, shows Domain as its type
   and saves a rename-free change. Pinned in Task B7.
5. A client still sending `domains.domain.$in`: it gets the unsupported-operator error, not a
   500. Pinned in Task B4.

---

# Part A: `relaticle/custom-fields`

### Task A1: The `systemOnly()` flag and the picker filter

**Files:**
- Modify: `src/Data/FieldTypeData.php`
- Modify: `src/FieldTypeSystem/FieldSchema.php`
- Modify: `src/Collections/FieldTypeCollection.php`
- Modify: `src/Filament/Management/Forms/Components/TypeField.php`
- Modify: `src/Filament/Management/Schemas/FieldForm.php:286`
- Create: `tests/Fixtures/FieldTypes/SystemProbeFieldType.php`
- Test: `tests/Feature/Admin/Pages/CustomFieldsFieldManagementTest.php`

**Interfaces:**
- Produces: `FieldSchema::systemOnly(bool $systemOnly = true): self`,
  `FieldTypeData::$systemOnly` (bool, default `false`),
  `FieldTypeCollection::selectable(?string $except = null): static`.

- [ ] **Step 1: Branch**

```bash
cd /Users/manuk/Herd/custom-fields
git fetch origin
git switch -c feat/system-only-field-types --no-track origin/3.x
```

- [ ] **Step 2: Add the fixture type**

`tests/Fixtures/FieldTypes/SystemProbeFieldType.php`:

```php
<?php

declare(strict_types=1);

namespace Relaticle\CustomFields\Tests\Fixtures\FieldTypes;

use Relaticle\CustomFields\FieldTypeSystem\BaseFieldType;
use Relaticle\CustomFields\FieldTypeSystem\FieldSchema;

class SystemProbeFieldType extends BaseFieldType
{
    public function configure(): FieldSchema
    {
        return FieldSchema::text()
            ->key('system-probe')
            ->label('System probe')
            ->icon('heroicon-o-lock-closed')
            ->systemOnly();
    }
}
```

- [ ] **Step 3: Write the failing tests**

Append to `tests/Feature/Admin/Pages/CustomFieldsFieldManagementTest.php`, with
`use Relaticle\CustomFields\Facades\CustomFieldsType;` and
`use Relaticle\CustomFields\Tests\Fixtures\FieldTypes\SystemProbeFieldType;` at the top:

```php
describe('System-only field types', function (): void {
    beforeEach(function (): void {
        CustomFieldsType::register([SystemProbeFieldType::class]);

        $this->section = CustomFieldSection::factory()
            ->forEntityType($this->userEntityType)
            ->create();
    });

    it('keeps a system-only type out of the selectable types', function (): void {
        expect(CustomFieldsType::toCollection()->pluck('key'))->toContain('system-probe')
            ->and(CustomFieldsType::toCollection()->selectable()->pluck('key'))->not->toContain('system-probe')
            ->and(CustomFieldsType::toCollection()->selectable()->pluck('key'))->toContain('text');
    });

    it('keeps the type of the field being edited selectable', function (): void {
        expect(CustomFieldsType::toCollection()->selectable('system-probe')->pluck('key'))->toContain('system-probe');
    });

    it('rejects a system-only type sent to the create form', function (): void {
        livewire(ManageCustomFieldSection::class, [
            'section' => $this->section,
            'entityType' => $this->userEntityType,
        ])
            ->callAction('createField', [
                'name' => 'Probe',
                'code' => 'probe',
                'type' => 'system-probe',
                'entity_type' => $this->userEntityType,
            ])
            ->assertHasActionErrors(['type']);

        expect(CustomField::query()->withoutGlobalScopes()->where('code', 'probe')->exists())->toBeFalse();
    });

    it('still creates a field of a selectable type', function (): void {
        livewire(ManageCustomFieldSection::class, [
            'section' => $this->section,
            'entityType' => $this->userEntityType,
        ])
            ->callAction('createField', [
                'name' => 'Plain',
                'code' => 'plain',
                'type' => 'text',
                'entity_type' => $this->userEntityType,
            ])
            ->assertHasNoActionErrors();
    });
});
```

- [ ] **Step 4: Run them and watch them fail**

Run: `vendor/bin/pest tests/Feature/Admin/Pages/CustomFieldsFieldManagementTest.php --filter='System-only'`
Expected: FAIL, `Call to undefined method ...FieldSchema::systemOnly()`.

- [ ] **Step 5: Implement**

`src/Data/FieldTypeData.php`: add one constructor property after `supportsUniqueConstraint`:

```php
        public bool $systemOnly = false,
```

`src/FieldTypeSystem/FieldSchema.php`: add the property beside `$supportsUniqueConstraint`,
the method after `supportsUniqueConstraint()`, and the named argument in `data()`:

```php
    private bool $systemOnly = false;
```

```php
    /**
     * Keep this type out of the field type picker. Only code can create a field of it.
     */
    public function systemOnly(bool $systemOnly = true): self
    {
        $this->systemOnly = $systemOnly;

        return $this;
    }
```

```php
            supportsUniqueConstraint: $this->supportsUniqueConstraint,
            systemOnly: $this->systemOnly,
```

`src/Collections/FieldTypeCollection.php`:

```php
    public function selectable(?string $except = null): static
    {
        return $this->filter(fn (FieldTypeData $fieldType): bool => ! $fieldType->systemOnly || $fieldType->key === $except);
    }
```

`src/Filament/Management/Forms/Components/TypeField.php`: replace both
`CustomFieldsType::toCollection()` calls with `$this->selectableTypes()` and add:

```php
    private function selectableTypes(): FieldTypeCollection
    {
        $record = $this->getRecord();

        return CustomFieldsType::toCollection()->selectable($record instanceof CustomField ? $record->type : null);
    }
```

Import `Relaticle\CustomFields\Collections\FieldTypeCollection` and
`Relaticle\CustomFields\Models\CustomField`.

Filament validates a single select against its `options()` list
(`Select::getInValidationRuleValues()`), so filtering the options should already refuse a
system-only key sent by a direct Livewire call. The `rejects a system-only type sent to the
create form` test is the proof. If it still fails after this step, add the explicit rule to
the chain in `setUp()` and nothing else:

```php
            ->in(fn (): array => $this->selectableTypes()->pluck('key')->all())
```

Never weaken the assertion.

`src/Filament/Management/Schemas/FieldForm.php:286`:

```php
                                    $record->type ?? CustomFieldsType::toCollection()->selectable()->first()->key
```

- [ ] **Step 6: Run the tests and the gates**

Run: `vendor/bin/pest tests/Feature/Admin/Pages/CustomFieldsFieldManagementTest.php tests/Feature/FieldFormSchemaExtensionTest.php`
Expected: PASS.
Run: `composer test:lint && composer test:refactor && composer test:types`
Expected: all three clean.

- [ ] **Step 7: Commit**

```bash
git add src tests
git commit -m "feat: let a field type opt out of the type picker"
```

### Task A2: Remove `link_variant`

**Files:**
- Modify: `src/FieldTypeSystem/Definitions/LinkFieldType.php`
- Modify: `tests/Feature/Models/CustomFieldValueNormalizationTest.php`
- Modify: `tests/Feature/Rules/UniqueCustomFieldValueTest.php:684-728`
- Modify: `docs/content/2.essentials/6.data-model.md:141-145`
- Modify: `CHANGELOG.md` only if the repo's release flow does not generate it. Check
  `git log -3 --format=%s -- CHANGELOG.md` first: the last entries read `chore: update
  CHANGELOG for vX`, so leave it to the release.

**Interfaces:**
- Produces: `LinkFieldType::normalize()` no longer exists, so
  `BaseFieldType::normalize()` applies and returns `setValue($value)`.
  `LinkFieldType::equivalentValues()` is unchanged. Part B relies on both.

- [ ] **Step 1: Change the tests first**

In `CustomFieldValueNormalizationTest.php`:

- Delete the `$this->domainLinkField` fixture from `beforeEach`.
- Delete the datasets `domain links` and `empty links`, and these tests: `stores a
  domain-variant link as its bare lowercase host`, `normalizes a domain-variant link to the
  same value when applied twice`, `keeps a domain-variant value it cannot parse as a host`,
  `stores a domain-variant link saved outside the panel form as its bare host`, both `strips
  stacked schemes...` tests, `stores a www host with no registrable part in one lowercase
  spelling`, and `drops list items that normalize to nothing`.
- Keep the dataset `unparseable links` only if another test still uses it. If none does,
  delete it.
- In `keeps the path of a url-variant link`, drop the dataset and the `$variant` parameter,
  and rename it:

```php
it('ignores a link_variant setting and keeps the path', function (): void {
    $this->linkField->update(['settings' => new CustomFieldSettingsData(
        allow_multiple: true,
        max_values: 5,
        additional: ['link_variant' => 'domain'],
    )]);

    expect(SafeValueConverter::toDbSafe(['HTTPS://www.LinkedIn.com/Company/Acme', '  http://acme.com/Path  '], 'link', $this->linkField->refresh()))
        ->toBe(['https://www.linkedin.com/Company/Acme', 'http://acme.com/Path']);
});
```

In `UniqueCustomFieldValueTest.php`, delete the two tests `normalizes candidates with the
field setting so a domain variant catches a pasted url` and `matches a held value stored in a
legacy format against the domain variant`. Relaticle's suite carries both behaviours for its
own type (Task B2).

- [ ] **Step 2: Run and watch the new test fail**

Run: `vendor/bin/pest tests/Feature/Models/CustomFieldValueNormalizationTest.php --filter='ignores a link_variant'`
Expected: FAIL, the stored values are `['linkedin.com', 'acme.com']`.

- [ ] **Step 3: Implement**

In `LinkFieldType.php` delete the `normalize()` method, the `WHITESPACE` constant, and the
`Illuminate\Support\Str` import. `equivalentValues()` keeps calling
`$this->normalize($value, $customField)`, which now resolves to the base method. Keep the
`CustomField` import, because `equivalentValues()` still names it.

- [ ] **Step 4: Update the docs**

In `docs/content/2.essentials/6.data-model.md` delete the table row `Link, domain variant`
and replace the paragraph under the table with:

```markdown
A link keeps its scheme because `http://` and `https://` can open different pages.

A field type that only code should create calls `->systemOnly()` in `configure()`. The type
still resolves and validates, and the field type picker leaves it out.
```

- [ ] **Step 5: Run the tests and the gates**

Run: `vendor/bin/pest tests/Feature/Models/CustomFieldValueNormalizationTest.php tests/Feature/Rules/UniqueCustomFieldValueTest.php`
Expected: PASS.
Run: `git grep -n 'link_variant' -- src docs`
Expected: no output.
Run: `composer test:lint && composer test:refactor && composer test:types`
Expected: clean.

- [ ] **Step 6: Commit and push the branch**

```bash
git add -A
git commit -m "feat!: drop the internal link_variant setting from the link type"
git push -u origin feat/system-only-field-types
```

Open the PR against `3.x` with a `[3.x]` title suffix. Show the body draft before posting.
Do not tag.

---

# Part B: `relaticle/relaticle`

### Task B1: Point Relaticle at the package branch

**Files:**
- Modify: `composer.json`, `composer.lock`

- [ ] **Step 1: Require the branch**

```bash
composer require "relaticle/custom-fields:dev-feat/system-only-field-types as 3.14.0"
```

If Composer cannot find the branch, add a temporary `vcs` repository entry for
`https://github.com/relaticle/custom-fields` and retry.

- [ ] **Step 2: Confirm the baseline breaks where expected**

Run: `php artisan test --compact tests/Feature/Api/V1/CompaniesApiTest.php --filter='domain'`
Expected: FAIL. `domains` is still a `link` with a setting nothing reads, so
`https://www.Acme.com/pricing` is stored with its path. These failures are the repro that
Tasks B2 and B3 clear.

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock
git commit -m "chore(deps): track the custom-fields system-only branch"
```

### Task B2: `DomainFieldType`

**Files:**
- Create: `app/Filament/CustomFields/DomainFieldType.php`
- Modify: `app/Enums/CustomFieldType.php`
- Modify: `app/Providers/AppServiceProvider.php:638`
- Modify: `lang/en/workspaces.php`
- Modify: `tests/Arch/ArchTest.php:455-478`
- Modify: `tests/Feature/ActivityLog/CustomFieldActivityTest.php:139-144`
- Test: `tests/Feature/Mcp/CustomFieldWritesTest.php`

**Interfaces:**
- Consumes: `FieldSchema::systemOnly()`, `LinkFieldType::equivalentValues()` (Part A).
- Produces: `CustomFieldType::DOMAIN` with value `'domain'`, and a registered type whose
  `normalize()` returns the bare lowercase host.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Mcp/CustomFieldWritesTest.php`, add one row to the dataset of `sets then
clears a value for every writable custom field type`, after the `link` row:

```php
    'domain' => ['domain', ['https://www.Acme.com/pricing?x=1#top'], ['acme.com']],
```

Append these tests to the same file. They write through the MCP tool to a company field of
the domain type, which is how every surface reaches the normalizer:

```php
function domainProbeField(Workspace $workspace): CustomField
{
    return CustomField::query()->create([
        'tenant_id' => $workspace->getKey(),
        'entity_type' => 'company',
        'code' => 'probe_domains',
        'name' => 'Probe domains',
        'type' => 'domain',
        'sort_order' => 70,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => true,
        'settings' => new CustomFieldSettingsData(allow_multiple: true, max_values: 5),
    ]);
}

it('stores every spelling of a domain as its bare lowercase host', function (string $input): void {
    $field = domainProbeField($this->workspace);
    $company = Company::factory()->for($this->workspace)->create();

    $company->saveCustomFieldValue($field, [$input]);

    expect(collect($company->refresh()->getCustomFieldValue($field))->all())->toBe(['acme.com']);
})->with([
    'scheme, www and path' => 'https://www.Acme.com/pricing?x=1#top',
    'bare' => 'acme.com',
    'userinfo and port' => 'http://user:secret@acme.com:8080/',
    'trailing dot' => 'ACME.COM.',
    'padded' => '  www.acme.com  ',
    'at sign in the query' => 'acme.com?ref=a@b.com',
    'repeated www' => 'www.www.acme.com',
    'non-breaking space' => "\u{00A0}https://acme.com",
    'zero-width characters' => "https://\u{200B}acme.com\u{FEFF}",
    'stacked schemes split by whitespace' => "http:// HTTPS://\thttp://\u{00A0}acme.com",
    'two hundred stacked schemes' => str_repeat('http://', 200).'Acme.com/x',
]);

it('keeps a domain value it cannot read as a host', function (string $input, string $stored): void {
    $field = domainProbeField($this->workspace);
    $company = Company::factory()->for($this->workspace)->create();

    $company->saveCustomFieldValue($field, [$input]);

    expect(collect($company->refresh()->getCustomFieldValue($field))->all())->toBe([$stored]);
})->with([
    'not a link' => ['N/A', 'N/A'],
    'tel uri' => ['tel:+14155550100', 'tel:+14155550100'],
    'www host with no registrable part' => ['WWW.CO', 'www.co'],
]);

it('drops a domain value that holds no host', function (string $empty): void {
    $field = domainProbeField($this->workspace);
    $company = Company::factory()->for($this->workspace)->create();

    $company->saveCustomFieldValue($field, [$empty, 'https://www.Acme.com/x']);

    expect(collect($company->refresh()->getCustomFieldValue($field))->all())->toBe(['acme.com']);
})->with(['scheme only' => 'https://', 'slash' => '/', 'www only' => 'www.']);
```

Add the imports the file lacks (`App\Models\Company`, `App\Models\Workspace`,
`Relaticle\CustomFields\Data\CustomFieldSettingsData`). If `saveCustomFieldValue()` needs the
tenant context, wrap the call the way the neighbouring tests in this file do.

`domainProbeField()` is a global Pest function. Run
`grep -rn 'function domainProbeField' tests` first and pick another name if it exists.

In `tests/Feature/ActivityLog/CustomFieldActivityTest.php` line 139-144, change the fixture
type from `'link'` to `'domain'` and drop `additional: ['link_variant' => 'domain']`. The two
`link_variant` fixtures in `CustomFieldFilterTest.php` change in Task B4, with the filter code
they depend on.

- [ ] **Step 2: Run and watch them fail**

Run: `php artisan test --compact tests/Feature/Mcp/CustomFieldWritesTest.php --filter='domain'`
Expected: FAIL, the `domain` type does not resolve.

- [ ] **Step 3: Add the enum case**

In `app/Enums/CustomFieldType.php` add `case DOMAIN = 'domain';` after `LINK`. Then run
`grep -n 'self::LINK' app/Enums/CustomFieldType.php` and give every hit a `DOMAIN` neighbour:

```php
            self::DOMAIN => 'array of domain strings; a URL is reduced to its host',   // inputFormat()
            self::DOMAIN => 'heroicon-o-globe-alt',                                    // icon()
            self::DOMAIN => ['example.com'],                                           // example()
            self::DOMAIN => 'as a bare host or a full URL, with or without www',       // filterMatching()
```

In `filterExample()` add `self::DOMAIN` to the `$has_any` arm. The trailing notes above name
the method and are not written into the file.

- [ ] **Step 4: Add the type**

`app/Filament/CustomFields/DomainFieldType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use App\Enums\CustomFieldType;
use Illuminate\Support\Str;
use Relaticle\CustomFields\FieldTypeSystem\BaseFieldType;
use Relaticle\CustomFields\FieldTypeSystem\Definitions\LinkFieldType;
use Relaticle\CustomFields\FieldTypeSystem\FieldSchema;
use Relaticle\CustomFields\Filament\Integration\Components\Forms\LinkComponent;
use Relaticle\CustomFields\Filament\Integration\Components\Infolists\LinkEntry;
use Relaticle\CustomFields\Filament\Integration\Components\Tables\Columns\LinkColumn;
use Relaticle\CustomFields\Models\CustomField;

final class DomainFieldType extends BaseFieldType
{
    private const string WHITESPACE = '[\s\x{00A0}\x{200B}\x{FEFF}\x{3000}]';

    public function configure(): FieldSchema
    {
        return FieldSchema::multiChoice()
            ->key(CustomFieldType::DOMAIN->value)
            ->label(__('workspaces.custom_field_types.domain'))
            ->icon('heroicon-o-globe-alt')
            ->formComponent(LinkComponent::class)
            ->tableColumn(LinkColumn::class)
            ->infolistEntry(LinkEntry::class)
            ->priority(61)
            ->supportsMultiValue()
            ->supportsUniqueConstraint()
            ->withArbitraryValues()
            ->withoutUserOptions()
            ->systemOnly()
            ->defaultItemValidationRules(['max:2048', 'regex:/^(https?:\/\/)?([a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}(\/.*)?$/']);
    }

    public function setValue(string $value): string
    {
        $authority = Str::of($value)
            ->lower()
            ->replaceMatches('#'.self::WHITESPACE.'+#u', '')
            ->replaceMatches('#^[a-z][a-z0-9+.-]*://#', '')
            ->before('/')
            ->before('?')
            ->before('#')
            ->replaceMatches('#^.*@#', '')
            ->before(':');

        $host = (string) $authority->replaceMatches('#^(www\.)+#', '')->rtrim('.');

        if ($host === '' || str_contains($host, '.')) {
            return $host;
        }

        if ($authority->startsWith('www.')) {
            return "www.{$host}";
        }

        $unwrapped = (string) preg_replace('#^(?:https?://'.self::WHITESPACE.'*)+#iu', '', trim($value));

        return $unwrapped === $value ? $value : $this->setValue($unwrapped);
    }

    /**
     * @return list<string>
     */
    public function equivalentValues(string $value, CustomField $customField): array
    {
        return array_values(array_unique([
            $this->setValue($value),
            ...(new LinkFieldType)->equivalentValues($value, $customField),
        ]));
    }
}
```

`setValue()` is the body of the package's `LinkFieldType::normalize()` at tag `v3.13.1`,
moved as it is. `equivalentValues()` adds the link spellings (`www.acme.com`,
`https://acme.com`, `http://acme.com`) so a value stored before normalization still matches.
That is Review Focus item 1.

- [ ] **Step 5: Register it, add the label, satisfy the arch gate**

`app/Providers/AppServiceProvider.php`, in the existing `CustomFieldsType::register([...])`:

```php
            'domain' => DomainFieldType::class,
```

`lang/en/workspaces.php`, a new top-level key:

```php
    'custom_field_types' => [
        'domain' => 'Domain',
    ],
```

`tests/Arch/ArchTest.php`: the class imports `Relaticle\CustomFields\Models\CustomField`
because the inherited `equivalentValues()` signature names it. Add
`'App\Filament\CustomFields\DomainFieldType',` to the ignoring list of `App must not use
custom-fields package models directly`, in the reviewed part of the list, above the comment
that starts `Slipped past`. `App\Filament` is already exempt from `avoid inheritance`.

- [ ] **Step 6: Run the tests**

Run: `php artisan test --compact tests/Feature/Mcp/CustomFieldWritesTest.php tests/Feature/ActivityLog/CustomFieldActivityTest.php`
Expected: PASS.
Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 7: Commit**

```bash
git add app lang tests
git commit -m "feat(custom-fields): add a system-only domain field type"
```

### Task B3: Point `domains` at the type and migrate stored fields

**Files:**
- Modify: `app/Enums/CustomFields/CompanyField.php:37-86`
- Modify: `app/Enums/CustomFields/CustomFieldTrait.php:138-145`
- Modify: `app/Listeners/CreateWorkspaceCustomFields.php:145`
- Create: `database/migrations/<timestamp>_change_company_domains_to_the_domain_field_type.php`
- Test: `tests/Feature/Api/V1/CompaniesApiTest.php`

**Interfaces:**
- Consumes: `CustomFieldType::DOMAIN` (Task B2).
- Produces: every new workspace gets `company.domains` with `type = 'domain'` and no
  `link_variant` key.

- [ ] **Step 1: Write the failing test**

Append inside `describe('custom fields', ...)` in `tests/Feature/Api/V1/CompaniesApiTest.php`
(line 307, the block that holds `rejects a domain another company already uses in another
format`):

```php
    it('creates the domains field as a domain type with no variant setting', function (): void {
        $domains = WorkspaceCustomField::byCode($this->workspace->id, 'company', 'domains');

        expect($domains->type)->toBe('domain')
            ->and($domains->settings->additional)->toBe([]);
    });
```

`CustomFieldSettingsData::$additional` defaults to `[]`, so the assertion is exact.

- [ ] **Step 2: Run and watch it fail**

Run: `php artisan test --compact tests/Feature/Api/V1/CompaniesApiTest.php --filter='creates the domains field'`
Expected: FAIL, type is `link`.

- [ ] **Step 3: Implement**

`CompanyField::getFieldType()`:

```php
        return match ($this) {
            self::ICP => CustomFieldType::TOGGLE->value,
            self::DOMAINS => CustomFieldType::DOMAIN->value,
            self::LINKEDIN => CustomFieldType::LINK->value,
        };
```

Delete `CompanyField::additionalSettings()`, `CustomFieldTrait::additionalSettings()` with its
docblock, and the `additional: $enum->additionalSettings(),` argument in
`CreateWorkspaceCustomFields`. Confirm nothing else calls it:
`grep -rn 'additionalSettings' app packages tests` returns no output.

- [ ] **Step 4: Write the migration**

Run: `php artisan make:migration change_company_domains_to_the_domain_field_type --no-interaction`

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
            ->update([
                'type' => 'domain',
                'settings' => DB::raw(<<<'SQL'
                    case
                        when jsonb_typeof(settings::jsonb -> 'additional') = 'object'
                            then (settings::jsonb #- '{additional,link_variant}')::json
                        else settings
                    end
                    SQL),
            ]);
    }
};
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact tests/Feature/Api/V1/CompaniesApiTest.php tests/Feature/Api/V1/CompaniesUpsertApiTest.php tests/Feature/Mcp/CustomFieldWritesTest.php`
Expected: PASS, including the legacy-domain cases that use `LegacyCompanyDomains`.

- [ ] **Step 6: Rehearse the migration on anonymized production data**

Follow the four steps in the Database section of `.ai/guidelines/relaticle/core.md`.

1. Export read-only: `pg_dump -s`, `pg_dump -a -t migrations`, and `\copy` of
   `custom_fields` with `name` replaced by `'x'`.
2. Load into a scratch database. Save the before-state:

```sql
select type, count(*), count(*) filter (where settings::jsonb #> '{additional,link_variant}' is not null) as with_variant
from custom_fields group by type order by type;
select count(*) from custom_fields where entity_type = 'company' and code = 'domains';
```

3. `DB_DATABASE=<scratch> php artisan migrate --force`. Confirm only this migration ran.
   Re-run both queries. Expected: the `link` count drops by exactly the `company.domains`
   count, `domain` equals that count, and `with_variant` is 0 on every row.
4. Run `migrate` again and confirm it reports nothing to migrate. Drop the scratch database
   and delete the export.

Paste both query results, before and after, into the PR body.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app database tests
git commit -m "feat(custom-fields): store company domains as the domain field type"
```

### Task B4: The filter vocabulary

**Files:**
- Modify: `app/Queries/CustomFieldFilterSchema.php:84-90`
- Modify: `app/Queries/Filters/CustomFieldFilter.php:273, 349, 362`
- Test: `tests/Feature/Mcp/Filters/CustomFieldFilterTest.php`

**Interfaces:**
- Consumes: `CustomFieldType::DOMAIN`, the registered type (Task B2).
- Produces: `CustomFieldFilterSchema::operatorsForType('domain')` returns `$has_any`,
  `$has_none`, `$is_empty`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Mcp/Filters/CustomFieldFilterTest.php`:

```php
it('offers a domain field the list operators and no domain sub-field', function (): void {
    expect(array_keys(CustomFieldFilterSchema::operatorsForType('domain')))->toBe(['$has_any', '$has_none', '$is_empty']);
});

it('finds a company by any spelling of its domain', function (string $operand): void {
    $domains = WorkspaceCustomField::byCode($this->workspace->getKey(), 'company', 'domains');
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme']);
    $company->saveCustomFieldValue($domains, ['acme.com']);
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Ana', 'company_id' => $company->getKey()]);
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Bob']);

    expect(peopleNamesMatching($this->user, ['company' => ['custom_fields' => ['domains' => ['$has_any' => [$operand]]]]]))->toBe(['Ana']);
})->with([
    'bare host' => 'acme.com',
    'uppercase' => 'ACME.COM',
    'url with a path' => 'https://www.acme.com/about?x=1',
    'trailing slash' => 'acme.com/',
]);

it('rejects the domain sub-field on a domain field', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(ListPeopleTool::class, ['filter' => ['company' => ['custom_fields' => ['domains' => ['domain' => ['$in' => ['acme.com']]]]]]])
        ->assertHasErrors(['domains does not support domain.']);
});
```

Add imports for `App\Models\Company` and `Tests\Helpers\WorkspaceCustomField` if the file
lacks them.

In the same file, lines 791 and 808: change the fixture type from `'link'` to `'domain'` and
drop `additional: ['link_variant' => 'domain']`. Rename the two tests to `reads a domain
operand that stacks schemes as its host` and `finds a domain stored in a legacy spelling by
its url operand`. Their assertions stay as they are.

Then find every existing test that sends the sub-field to the real `domains`
field: `grep -rn "'domains' => \['domain'" tests`. Rewrite each to `$has_any` with the same
operands, keeping its assertions.

- [ ] **Step 2: Run and watch them fail**

Run: `php artisan test --compact tests/Feature/Mcp/Filters/CustomFieldFilterTest.php --filter='domain'`
Expected: FAIL, `operatorsForType('domain')` returns `[]`.

- [ ] **Step 3: Implement**

`CustomFieldFilterSchema::operatorsForType()`:

```php
            CustomFieldType::PHONE, CustomFieldType::DOMAIN,
            CustomFieldType::MULTI_SELECT, CustomFieldType::CHECKBOX_LIST, CustomFieldType::TAGS_INPUT => self::buildOperators(['$has_any', '$has_none'], 'array'),
```

`CustomFieldFilter::containsAny()` (line 273), add the type to the lowercase compare:

```php
        $matches = in_array($field->type, [CustomFieldType::EMAIL->value, CustomFieldType::LINK->value, CustomFieldType::DOMAIN->value], true)
```

`CustomFieldFilter::spellings()` (line 349), add `CustomFieldType::DOMAIN->value` to the type
list in the guard, and replace the check at line 362:

```php
            if ($field->type === CustomFieldType::DOMAIN->value) {
                $value = (string) preg_replace(self::LINK_WHITESPACE, '', $value);
            }
```

The comment above `LINK_WHITESPACE` names `LinkFieldType::normalize()`. Change it to name
`DomainFieldType::setValue()`. `DOMAIN_OF` gets no domain entry.

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact tests/Feature/Mcp/Filters/CustomFieldFilterTest.php`
Expected: PASS, including `publishes only $ operators and the domain sub-field`.

- [ ] **Step 5: Sweep the published wording**

Run: `grep -rn 'domain sub-field\|\.domain\b.*\$in\|domains.*domain' packages/Documentation/resources app/Mcp resources/views/scribe lang --include='*.md' --include='*.php' --include='*.blade.php' | head -40`
Fix any line that tells a reader to filter company `domains` through the `domain` sub-field.
The email and link examples stay.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app tests packages lang resources
git commit -m "feat(filters): give domain fields the list operators only"
```

### Task B5: The remaining branches on the link type

**Files:**
- Modify: `app/Console/Commands/NormalizeCustomFieldValuesCommand.php:19, 49, 80`
- Modify: `app/Support/CustomFields/CustomFieldInput.php:76`
- Modify: `packages/Chat/src/Services/Tools/ProposalFieldSchemaDescriber.php:140`
- Modify: `packages/Chat/src/Services/Tools/CustomFieldsDisplayFormatter.php:171, 279, 312`
- Test: `tests/Feature/Commands/NormalizeCustomFieldValuesCommandTest.php`
- Test: `tests/Feature/Chat/CreateCustomFieldToolTest.php`

**Interfaces:**
- Consumes: `CustomFieldType::DOMAIN`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Chat/CreateCustomFieldToolTest.php`, after `returns error for a
non-allowlisted field type`:

```php
it('refuses to propose a domain field', function (): void {
    $decoded = proposeCustomFields($this->convId, [
        'entity_type' => 'company',
        'name' => 'Second domains',
        'type' => 'domain',
    ]);

    expect($decoded)->toHaveKey('error')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});
```

This one passes already, because the allowlist excludes `domain`. It pins decision 1. The
failing repro for this task is the existing command test: with `domains` now a `domain` type,
the command skips it.

- [ ] **Step 2: Run and watch the command tests fail**

Run: `php artisan test --compact tests/Feature/Commands/NormalizeCustomFieldValuesCommandTest.php`
Expected: FAIL in `normalizes phones and domains with force...` and both shared-domain tests.

- [ ] **Step 3: Implement**

`NormalizeCustomFieldValuesCommand`:

```php
#[Description('Re-run every phone, link and domain custom field value through its field type normalizer')]
```

```php
            ->whereIn('type', [CustomFieldType::PHONE->value, CustomFieldType::LINK->value, CustomFieldType::DOMAIN->value])
```

```php
        $isDomain = $field->type === CustomFieldType::DOMAIN->value;
```

`CustomFieldInput::normalizeValue()` already has its `DOMAIN` arm. It moved to Task B2, because
the writable-types test there writes through it.

`ProposalFieldSchemaDescriber::kindFor()` and `CustomFieldsDisplayFormatter::displayType()`
and `storedValues()`, each `if`:

```php
        if (in_array($field->type, [CustomFieldType::LINK->value, CustomFieldType::DOMAIN->value], true)) {
```

`CustomFieldsDisplayFormatter` line 312: add `CustomFieldType::DOMAIN->value` to the list.

- [ ] **Step 4: Sweep for anything the list missed**

Run: `grep -rn 'CustomFieldType::LINK\|link_variant' app packages tests database/seeders`
Expected: every `CustomFieldType::LINK` hit either has a `DOMAIN` neighbour or is about URL
links only (`PeopleField::LINKEDIN`, `CompanyField::LINKEDIN`, `DOMAIN_OF`,
`applyDomain()`'s www strip). `link_variant` appears only in
`database/migrations/2026_10_03_000000_set_domain_link_variant_on_company_domains.php`.
Run: `grep -rn 'CustomFieldType' packages/SystemAdmin`
Expected: no output.

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact tests/Feature/Commands/NormalizeCustomFieldValuesCommandTest.php tests/Feature/Chat/CreateCustomFieldToolTest.php tests/Feature/Chat/AllCustomFieldsViaChatTest.php tests/Feature/CRM/SurfaceParityTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app packages tests
git commit -m "refactor(custom-fields): branch on the domain type instead of the link setting"
```

### Task B6: Static gates and the pull request

- [ ] **Step 1: Run the gates once**

```bash
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:lint
composer test:arch
```

Expected: all clean. PHPStan reports any `match` over `CustomFieldType` that lacks a `DOMAIN`
arm. Add the arm beside `LINK`, never an ignore.

- [ ] **Step 2: Rename the branch before the first push**

Ask the user to confirm the name `feat/domain-field-type`. A pushed placeholder branch cannot
be renamed under a pull request.

- [ ] **Step 3: Push and open the PR**

Show the PR body draft and wait for "post". The body carries the rehearsal output from Task
B3 and the note that `domains` now reports `type: domain`.

The body also carries a deploy step. After `migrate`, a Horizon worker still on the old
release meets `type = 'domain'` with no `DOMAIN` case, and `CustomFieldType::from()` throws on
a company write. So: `php artisan horizon:pause` before `migrate`, `php artisan
horizon:terminate` after, as `2026_09_24_100100_backfill_agent_conversation_message_steps`
did. A self-hosted upgrade needs nothing extra. Watch CI as a background task:

```bash
gh run watch --exit-status $(gh run list --branch feat/domain-field-type --workflow Tests --limit 1 --json databaseId --jq '.[0].databaseId')
```

### Task B7: Browser walk

Read the `agent-browser-relaticle` skill first. Screenshots go under `.context/`.

- [ ] **Step 1: Company form, Chromium.** Open a company, add `https://www.Acme.com/pricing`
  to Domains, save. Expected: the record shows `acme.com`. Add the same host to a second
  company. Expected: the unique error on the field.
- [ ] **Step 2: Settings picker.** Open workspace settings, Custom Fields, start a new company
  field. Expected: the type list has Link and no Domain. Search the list for "domain".
  Expected: no result.
- [ ] **Step 3: Edit the system field.** Open the `Domains` field for edit. Expected: the type
  select shows Domain, disabled, and the form saves with no error. This is Review Focus 4.
- [ ] **Step 4: Light, dark, and a mobile viewport** for the company form and the settings page.
- [ ] **Step 5: WebKit.** Run the browser tests that cover the company form and the custom
  fields settings page with `--browser safari`. Find them with
  `grep -rln 'domains\|Custom Fields' tests/Browser`.
- [ ] **Step 6: Chat.** With Horizon, Redis queue and Reverb running, ask Rela to set a
  company's domain to a full URL and approve the proposal. Expected: the card shows the
  value as a link and the record stores the host.

### Task B8: `finalize-pr` and the real integrations, end to end

This is the last gate before any release step. It runs on the pushed head of the Relaticle
PR, still pointed at the package branch. Nothing here uses a fake, a sync queue or a seeded
shortcut: Horizon runs, `QUEUE_CONNECTION=redis`, Reverb is up.

- [ ] **Step 1: Run `finalize-pr`.** Invoke the `finalize-pr` skill on the Relaticle PR and
  follow it through in one turn: review, refactor, tests, report. Fix what it finds, push, and
  let CI pass on the new head before the next step.
- [ ] **Step 2: REST API with a real token.** Create a personal access token in the app. With
  `curl` against the local site: create a company with `domains: ["https://www.Acme.com/pricing"]`
  and expect `acme.com` back; list custom fields and expect `domains` with `type: domain`;
  filter `filter[custom_fields][domains][$has_any][]=https://acme.com/about` and expect the
  company; send the `domain` sub-field on `domains` and expect a 422 naming the unsupported
  operator; upsert with `match.value: "www.acme.com"` and expect the same id.
- [ ] **Step 3: MCP server from a real client.** Connect the `relaticle-local` MCP server
  (it refused connections at planning time, so start it first). Call `get-crm-schema-tool` for
  `company` and expect `domains` as `domain` with `$has_any` and `$has_none`. Call
  `create-company-tool` with a URL domain, then `list-companies-tool` filtered by that host.
- [ ] **Step 4: Rela with the real model provider.** In the browser, ask Rela to create a
  company with a URL domain, then to change it, then to find the company by its domain.
  Walk send, stream, proposal card, approve. Ask Rela to add a second domain field to
  companies and expect a refusal, not a proposal. Check the workspace `.env` AI keys first:
  a 401 there means stale keys, not a defect.
- [ ] **Step 5: Mailbox sync against the real test mailbox.** Seed it with
  `php artisan db:seed --class=LocalMailboxSeeder`, connect the Gmail test mailbox and sync.
  Expected: `AutoCreateCompanyAction` creates a company whose `domains` holds the sender's
  bare host, a second mail from the same domain creates no duplicate, and
  `EmailVisibilityService` still links mail to the company by domain. Repeat the sync with the
  local Microsoft mailbox if it is configured.
- [ ] **Step 6: Favicon fetch.** Create a company with a real domain and let the queue run
  `FetchFaviconForCompany`. Expected: the logo appears. The job reads the field by its code,
  so this proves the type change did not move it.
- [ ] **Step 7: CSV import.** Import a companies file through the wizard with a website
  column holding `https://www.acme.com/about`, a bare host, and a duplicate of an existing
  domain. Expected: the column maps to Domains, the first two store bare hosts, and the
  duplicate row reports the unique error.
- [ ] **Step 8: Migrated data, not fresh data.** Restore a local database dump taken before
  this branch, run `php artisan migrate`, and repeat steps 2 and 4 against a workspace that
  existed before the migration. Fresh workspaces never exercise the migration.
- [ ] **Step 9: Report.** List every step with its result and evidence (response bodies,
  screenshots under `.context/`). A step that could not run is reported as not run, with the
  reason. Fix defects at their layer, re-run the failing step, and show the new output.

### Task B9: Release and close

Starts only after Task B8 reports clean. Each step waits for an explicit instruction from
the user.

- [ ] **Step 1:** Merge the package PR into `3.x`. Release with
  `gh release create v3.14.0 --latest` from `3.x`. Confirm
  `https://repo.packagist.org/p2/relaticle/custom-fields.json` lists `v3.14.0`.
- [ ] **Step 2:** In Relaticle run `composer require "relaticle/custom-fields:^3.14"`, remove
  any temporary `vcs` repository entry, commit `chore(deps): require relaticle/custom-fields
  3.14`, push, and let CI pass on that commit.
- [ ] **Step 3:** Forward-port Part A to `feat/4.0` on its own branch and PR.
- [ ] **Step 4:** Merge the Relaticle PR. The release note says: `domains` reports
  `type: domain` on the API, the MCP schema and chat, and filters with `$has_any` and
  `$has_none`.
- [ ] **Step 5:** Close #933 with a link to the PR. Draft the comment first.
- [ ] **Step 6: #929.** Run one read-only production query:

```sql
select count(*)
from custom_field_values v
join custom_fields f on f.id = v.custom_field_id
where f.type = 'link'
  and exists (select 1 from jsonb_array_elements_text(
        case when jsonb_typeof(v.json_value::jsonb) = 'array' then v.json_value::jsonb else '[]'::jsonb end
      ) as e where e like '%/');
```

  Zero rows: close #929 with a comment citing package 3.13.1 and PR #906. Any rows: add a
  migration that re-queues `custom-fields:normalize-values` in the shape of
  `2026_10_03_000100_queue_custom_field_value_normalization`, and close it after that deploys.
