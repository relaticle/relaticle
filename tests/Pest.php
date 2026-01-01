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

use App\Models\Team;
use App\Models\User;
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
        $originalDataProperty = $reflection->getProperty('originalData');
        $originalDataProperty->setValue($importer, $data);
    }
}
