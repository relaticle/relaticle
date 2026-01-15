<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

pest()->extend(Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Globally disable events to prevent demo record creation during tests
        Event::fake();
    })
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/**
 * Livewire testing helper - replacement for pest-plugin-livewire.
 *
 * @param  class-string  $component
 * @param  array<string, mixed>  $params
 */
function livewire(string $component, array $params = []): \Livewire\Features\SupportTesting\Testable
{
    return \Livewire\Livewire::test($component, $params);
}

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/*
|--------------------------------------------------------------------------
| Import Test Helpers
|--------------------------------------------------------------------------
|
| Shared helper functions for ImportWizard module tests.
|
*/

use App\Enums\CustomFields\CompanyField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldSection;
use App\Models\CustomFieldValue;
use App\Models\People;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Relaticle\CustomFields\Services\TenantContextService;
use Relaticle\ImportWizard\Livewire\ImportWizard;
use Relaticle\ImportWizard\Models\Import;

/**
 * Create an Import record for testing.
 */
function createImportRecord(User $user, Team $team, string $importerClass = \Relaticle\ImportWizard\Filament\Imports\CompanyImporter::class): Import
{
    return Import::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'importer' => $importerClass,
        'file_name' => 'test.csv',
        'file_path' => '/tmp/test.csv',
        'total_rows' => 1,
    ]);
}

/**
 * Set data on an importer instance via reflection.
 */
function setImporterData(object $importer, array $data): void
{
    $reflection = new ReflectionClass($importer);
    $dataProperty = $reflection->getProperty('data');
    $dataProperty->setValue($importer, $data);

    if ($reflection->hasProperty('originalData')) {
        $reflection->getProperty('originalData')->setValue($importer, $data);
    }
}

/**
 * Setup common import test context (user, team, tenant).
 */
function setupImportTestContext(): array
{
    $team = Team::factory()->create();
    $user = User::factory()->withPersonalTeam()->create();
    $user->teams()->attach($team);

    test()->actingAs($user);
    Filament::setTenant($team);
    TenantContextService::setTenantId($team->id);

    return ['user' => $user, 'team' => $team];
}

/**
 * Create a test CSV file with given content.
 */
function createTestCsv(string $content, string $filename = 'test.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($filename, $content);
}

/**
 * Get return URL for given entity type.
 */
function getReturnUrl(Team $team, string $entityType): string
{
    return match ($entityType) {
        'companies' => route('filament.app.resources.companies.index', ['tenant' => $team]),
        'people' => route('filament.app.resources.people.index', ['tenant' => $team]),
        'opportunities' => route('filament.app.resources.opportunities.index', ['tenant' => $team]),
        'tasks' => route('filament.app.resources.tasks.index', ['tenant' => $team]),
        'notes' => route('filament.app.resources.notes.index', ['tenant' => $team]),
        default => '/',
    };
}

/**
 * Create emails custom field for People entity.
 */
function createEmailsCustomField(Team $team): CustomField
{
    $section = CustomFieldSection::withoutGlobalScopes()->firstOrCreate(
        ['code' => 'contact_information', 'tenant_id' => $team->id, 'entity_type' => 'people'],
        ['name' => 'Contact Information', 'type' => 'section', 'sort_order' => 1]
    );

    return CustomField::withoutGlobalScopes()->firstOrCreate(
        ['code' => 'emails', 'tenant_id' => $team->id, 'entity_type' => 'people'],
        [
            'custom_field_section_id' => $section->id,
            'name' => 'Emails',
            'type' => 'email',
            'sort_order' => 1,
            'active' => true,
            'system_defined' => true,
        ]
    );
}

/**
 * Set email value on a Person.
 */
function setPersonEmail(People $person, string|array $email, CustomField $field): void
{
    $emails = is_array($email) ? $email : [$email];
    CustomFieldValue::withoutGlobalScopes()->updateOrCreate(
        ['entity_type' => 'people', 'entity_id' => $person->id, 'custom_field_id' => $field->id],
        ['tenant_id' => $person->team_id, 'json_value' => $emails]
    );
}

/**
 * Create domains custom field for Company entity.
 */
function createDomainsField(Team $team): CustomField
{
    return CustomField::withoutGlobalScopes()->firstOrCreate(
        ['code' => CompanyField::DOMAINS->value, 'tenant_id' => $team->id, 'entity_type' => 'company'],
        [
            'name' => CompanyField::DOMAINS->getDisplayName(),
            'type' => 'link',
            'sort_order' => 1,
            'active' => true,
            'system_defined' => true,
        ]
    );
}

/**
 * Set domain value on a Company.
 */
function setCompanyDomain(Company $company, string|array $domain, ?CustomField $field = null): void
{
    $field ??= CustomField::withoutGlobalScopes()
        ->where('code', CompanyField::DOMAINS->value)
        ->where('tenant_id', $company->team_id)
        ->first();

    $domains = is_array($domain) ? $domain : [$domain];
    CustomFieldValue::withoutGlobalScopes()->updateOrCreate(
        ['entity_type' => 'company', 'entity_id' => $company->id, 'custom_field_id' => $field->id],
        ['tenant_id' => $company->team_id, 'json_value' => $domains]
    );
}

/**
 * Create an importer instance with data set via reflection.
 */
function createImporter(
    string $importerClass,
    User $user,
    Team $team,
    array $columnMap,
    array $data,
    array $options = []
): object {
    $import = createImportRecord($user, $team, $importerClass);
    $importer = new $importerClass($import, $columnMap, $options);
    setImporterData($importer, $data);

    return $importer;
}

/**
 * Create ImportWizard Livewire component for testing.
 */
function wizardTest(Team $team, string $entityType = 'companies'): \Livewire\Features\SupportTesting\Testable
{
    return Livewire\Livewire::test(ImportWizard::class, [
        'entityType' => $entityType,
        'returnUrl' => getReturnUrl($team, $entityType),
    ]);
}
