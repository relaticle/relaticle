<?php

declare(strict_types=1);

use App\Console\Commands\LocaleDiffCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

mutates(LocaleDiffCommand::class);

beforeEach(function (): void {
    $this->fixturePath = lang_path('test-fixtures');
    File::ensureDirectoryExists($this->fixturePath.'/en');
    File::ensureDirectoryExists($this->fixturePath.'/fr');

    File::put($this->fixturePath.'/en/sample.php', "<?php return ['a' => 'A', 'b' => 'B', 'c' => 'C'];");
    File::put($this->fixturePath.'/fr/sample.php', "<?php return ['a' => 'A-fr', 'd' => 'D-fr'];");
});

afterEach(function (): void {
    File::deleteDirectory($this->fixturePath);
});

it('reports missing and orphaned keys for a locale', function (): void {
    $this->artisan('locale:diff', ['locale' => 'fr', '--lang-path' => $this->fixturePath])
        ->expectsOutputToContain('Missing in fr: sample.b')
        ->expectsOutputToContain('Missing in fr: sample.c')
        ->expectsOutputToContain('Orphaned in fr: sample.d')
        ->assertExitCode(1);
});

it('exits 0 when fr matches en exactly', function (): void {
    File::put($this->fixturePath.'/fr/sample.php', "<?php return ['a' => 'A-fr', 'b' => 'B-fr', 'c' => 'C-fr'];");

    $this->artisan('locale:diff', ['locale' => 'fr', '--lang-path' => $this->fixturePath])
        ->assertExitCode(0);
});

it('exits 1 when the source en directory does not exist', function (): void {
    $this->artisan('locale:diff', ['locale' => 'fr', '--lang-path' => '/nonexistent/path/that/does/not/exist'])
        ->expectsOutputToContain('Source directory not found')
        ->assertExitCode(1);
});

it('exits 1 when the target locale directory does not exist', function (): void {
    $this->artisan('locale:diff', ['locale' => 'de', '--lang-path' => $this->fixturePath])
        ->expectsOutputToContain('Target directory not found')
        ->assertExitCode(1);
});

it('preserves slash in nested file paths to match Laravel translation namespace syntax', function (): void {
    File::ensureDirectoryExists($this->fixturePath.'/en/filament/resources');
    File::ensureDirectoryExists($this->fixturePath.'/fr/filament/resources');

    File::put($this->fixturePath.'/en/filament/resources/company.php', "<?php return ['label' => 'Company', 'plural_label' => 'Companies'];");
    File::put($this->fixturePath.'/fr/filament/resources/company.php', "<?php return ['label' => 'Entreprise'];");

    $this->artisan('locale:diff', ['locale' => 'fr', '--lang-path' => $this->fixturePath])
        ->expectsOutputToContain('Missing in fr: filament/resources/company.plural_label')
        ->assertExitCode(1);
});

it('compares top-level JSON files and labels keys with [json]', function (): void {
    File::put($this->fixturePath.'/en.json', json_encode(['Sign in' => 'Sign in', 'Sign out' => 'Sign out']));
    File::put($this->fixturePath.'/fr.json', json_encode(['Sign in' => 'Se connecter']));

    File::put($this->fixturePath.'/fr/sample.php', "<?php return ['a' => 'A-fr', 'b' => 'B-fr', 'c' => 'C-fr'];");

    $this->artisan('locale:diff', ['locale' => 'fr', '--lang-path' => $this->fixturePath])
        ->expectsOutputToContain('Missing in fr: [json] Sign out')
        ->assertExitCode(1);
});

it('emits json format when --format=json is set', function (): void {
    File::put($this->fixturePath.'/en.json', json_encode(['Sign out' => 'Sign out']));

    $output = json_decode(
        artisan('locale:diff', ['locale' => 'fr', '--lang-path' => $this->fixturePath, '--format' => 'json']),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($output['locale'])->toBe('fr')
        ->and($output['missing'])->toContain(['key' => 'sample.b', 'kind' => 'php'])
        ->and($output['missing'])->toContain(['key' => 'sample.c', 'kind' => 'php'])
        ->and($output['missing'])->toContain(['key' => 'Sign out', 'kind' => 'json'])
        ->and($output['orphaned'])->toContain(['key' => 'sample.d', 'kind' => 'php'])
        ->and($output['stale'])->toBe([]);
});

it('rejects unknown --format values', function (): void {
    $this->artisan('locale:diff', ['locale' => 'fr', '--lang-path' => $this->fixturePath, '--format' => 'yaml'])
        ->expectsOutputToContain('Invalid --format: yaml')
        ->assertExitCode(1);
});

it('writes a snapshot when --update-snapshot is set and skips diff output', function (): void {
    File::put($this->fixturePath.'/en.json', json_encode(['Sign in' => 'Sign in']));

    $this->artisan('locale:diff', ['locale' => 'fr', '--lang-path' => $this->fixturePath, '--update-snapshot' => true])
        ->expectsOutputToContain('Snapshot written')
        ->assertExitCode(0);

    $snapshotPath = $this->fixturePath.'/.snapshots/fr.json';
    expect(file_exists($snapshotPath))->toBeTrue();

    $contents = json_decode((string) file_get_contents($snapshotPath), true, flags: JSON_THROW_ON_ERROR);
    expect($contents['en_hashes'])->toHaveKey('sample.a')
        ->and($contents['en_hashes'])->toHaveKey('[json] Sign in')
        ->and($contents['en_hashes']['sample.a'])->toBe(hash('sha256', 'A'));
});

it('reports stale entries when en values drift after the snapshot was taken', function (): void {
    File::put($this->fixturePath.'/fr/sample.php', "<?php return ['a' => 'A-fr', 'b' => 'B-fr', 'c' => 'C-fr'];");

    $this->artisan('locale:diff', ['locale' => 'fr', '--lang-path' => $this->fixturePath, '--update-snapshot' => true])
        ->assertExitCode(0);

    File::put($this->fixturePath.'/en/sample.php', "<?php return ['a' => 'A (revised)', 'b' => 'B', 'c' => 'C'];");

    $this->artisan('locale:diff', ['locale' => 'fr', '--lang-path' => $this->fixturePath])
        ->expectsOutputToContain('Stale in fr: sample.a')
        ->assertExitCode(1);
});

it('stays silent on drift when no snapshot exists', function (): void {
    File::put($this->fixturePath.'/fr/sample.php', "<?php return ['a' => 'A-fr', 'b' => 'B-fr', 'c' => 'C-fr'];");

    $this->artisan('locale:diff', ['locale' => 'fr', '--lang-path' => $this->fixturePath])
        ->doesntExpectOutputToContain('Stale in fr')
        ->assertExitCode(0);
});

/**
 * Pest helper: invoke an Artisan command and capture its stdout as a string.
 *
 * @param  array<string, mixed>  $arguments
 */
function artisan(string $command, array $arguments): string
{
    Artisan::call($command, $arguments);

    return Artisan::output();
}
