<?php

declare(strict_types=1);

use App\Console\Commands\NormalizeCustomFieldValuesCommand;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Helpers\WorkspaceCustomField;

mutates(NormalizeCustomFieldValuesCommand::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
});

function writeRawJsonValue(string $entityId, CustomField $field, array $value): void
{
    DB::table('custom_field_values')->updateOrInsert(
        ['entity_id' => $entityId, 'custom_field_id' => $field->getKey()],
        ['id' => (string) str()->ulid(), 'tenant_id' => $field->tenant_id, 'entity_type' => $field->entity_type, 'json_value' => json_encode($value)],
    );
}

function readRawJsonValue(string $entityId, CustomField $field): array
{
    return json_decode((string) DB::table('custom_field_values')->where('entity_id', $entityId)->where('custom_field_id', $field->getKey())->value('json_value'), true);
}

function readRawJsonText(string $entityId, CustomField $field): string
{
    return (string) DB::table('custom_field_values')->where('entity_id', $entityId)->where('custom_field_id', $field->getKey())->value('json_value');
}

it('reports what it would change and writes nothing without force', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $phone = WorkspaceCustomField::byCode($this->workspace->getKey(), 'people', 'phone_number');
    writeRawJsonValue($person->getKey(), $phone, ['+1 415-555-0100', '555-123-4567']);

    $this->artisan('custom-fields:normalize-values')
        ->expectsOutputToContain('1 value(s) would change')
        ->expectsOutputToContain('1 national phone number(s) have no country code')
        ->expectsOutputToContain("Workspace {$this->workspace->getKey()}: 1 national phone number(s) in people.phone_number.")
        ->assertSuccessful();

    expect(readRawJsonValue($person->getKey(), $phone))->toBe(['+1 415-555-0100', '555-123-4567']);
});

it('normalizes phones and domains with force and changes nothing on a second run', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $phone = WorkspaceCustomField::byCode($this->workspace->getKey(), 'people', 'phone_number');
    $domains = WorkspaceCustomField::byCode($this->workspace->getKey(), 'company', 'domains');
    writeRawJsonValue($person->getKey(), $phone, ['+1 415-555-0100']);
    writeRawJsonValue($company->getKey(), $domains, ['https://www.Acme.com/', 'acme.com']);

    $this->artisan('custom-fields:normalize-values', ['--force' => true])
        ->expectsOutputToContain('2 value(s) changed.')
        ->assertSuccessful();

    expect(readRawJsonValue($person->getKey(), $phone))->toBe(['+14155550100'])
        ->and(readRawJsonValue($company->getKey(), $domains))->toBe(['acme.com']);

    $this->artisan('custom-fields:normalize-values', ['--force' => true])
        ->expectsOutputToContain('0 value(s) changed.')
        ->doesntExpectOutputToContain('national phone')
        ->assertSuccessful();
});

it('keeps a value edited between the chunk read and the row update', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $phone = WorkspaceCustomField::byCode($this->workspace->getKey(), 'people', 'phone_number');
    writeRawJsonValue($person->getKey(), $phone, ['+1 415-555-0100']);
    $edited = false;

    DB::listen(function (QueryExecuted $query) use (&$edited, $person, $phone): void {
        $readsPhoneValues = str_starts_with($query->sql, 'select')
            && str_contains($query->sql, '"custom_field_values"')
            && in_array($phone->getKey(), $query->bindings, true);

        if ($edited || ! $readsPhoneValues) {
            return;
        }

        $edited = true;

        DB::table('custom_field_values')
            ->where('entity_id', $person->getKey())
            ->where('custom_field_id', $phone->getKey())
            ->update(['json_value' => json_encode(['+14155550199'])]);
    });

    $this->artisan('custom-fields:normalize-values', ['--force' => true])
        ->expectsOutputToContain('0 value(s) changed.')
        ->assertSuccessful();

    expect($edited)->toBeTrue()
        ->and(readRawJsonValue($person->getKey(), $phone))->toBe(['+14155550199']);
});

it('reports domains two companies share after normalization', function (): void {
    $first = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $second = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $domains = WorkspaceCustomField::byCode($this->workspace->getKey(), 'company', 'domains');
    writeRawJsonValue($first->getKey(), $domains, ['https://acme.com']);
    writeRawJsonValue($second->getKey(), $domains, ['acme.com']);

    $this->artisan('custom-fields:normalize-values')
        ->expectsOutputToContain("Workspace {$this->workspace->getKey()}: acme.com is shared by 2 companies")
        ->assertSuccessful();
});

it('logs how many domains live companies share and leaves a trashed company out', function (): void {
    $domains = WorkspaceCustomField::byCode($this->workspace->getKey(), 'company', 'domains');
    [$first, $second, $trashed, $lone] = Company::factory()->recycle([$this->user, $this->workspace])->count(4)->create()->all();
    writeRawJsonValue($first->getKey(), $domains, ['acme.com']);
    writeRawJsonValue($second->getKey(), $domains, ['acme.com']);
    writeRawJsonValue($lone->getKey(), $domains, ['globex.com']);
    writeRawJsonValue($trashed->getKey(), $domains, ['globex.com']);
    $trashed->delete();
    Log::spy();

    $this->artisan('custom-fields:normalize-values')
        ->expectsOutputToContain('acme.com is shared by 2 companies')
        ->doesntExpectOutputToContain('globex.com is shared')
        ->assertSuccessful();

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $context['shared_domains'] === 1)
        ->once();
});

it('leaves values that are not a list of strings untouched and reports them', function (): void {
    $phone = WorkspaceCustomField::byCode($this->workspace->getKey(), 'people', 'phone_number');
    $shapes = [
        'nested' => [['+14155550100']],
        'keyed' => ['number' => '+1 415-555-0100'],
        'numbers' => [14155550100, true],
    ];
    $before = [];

    foreach ($shapes as $key => $shape) {
        $person = People::factory()->recycle([$this->user, $this->workspace])->create();
        writeRawJsonValue($person->getKey(), $phone, $shape);
        $before[$key] = [$person, readRawJsonText($person->getKey(), $phone)];
    }

    $this->artisan('custom-fields:normalize-values', ['--force' => true])
        ->expectsOutputToContain('0 value(s) changed.')
        ->expectsOutputToContain('3 value(s) have an unexpected shape and were left as they are.')
        ->assertSuccessful();

    foreach ($before as [$person, $text]) {
        expect(readRawJsonText($person->getKey(), $phone))->toBe($text);
    }
});

it('keeps the scheme and path of a url link and lowercases only its host', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $linkedin = WorkspaceCustomField::byCode($this->workspace->getKey(), 'people', 'linkedin');
    writeRawJsonValue($person->getKey(), $linkedin, ['HTTPS://www.LinkedIn.com/in/Jane-Doe/', 'www.linkedin.com/in/jane-doe']);

    $this->artisan('custom-fields:normalize-values', ['--force' => true])
        ->expectsOutputToContain('1 value(s) changed.')
        ->assertSuccessful();

    expect(readRawJsonValue($person->getKey(), $linkedin))->toBe(['https://www.linkedin.com/in/Jane-Doe', 'www.linkedin.com/in/jane-doe']);
});

it('counts only phone-shaped values as national numbers', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $phone = WorkspaceCustomField::byCode($this->workspace->getKey(), 'people', 'phone_number');
    writeRawJsonValue($person->getKey(), $phone, ['n/a', '(555) 123-4567']);

    $this->artisan('custom-fields:normalize-values')
        ->expectsOutputToContain('1 national phone number(s) have no country code')
        ->assertSuccessful();
});

it('logs one line per skipped row without its value and one summary line', function (): void {
    $phone = WorkspaceCustomField::byCode($this->workspace->getKey(), 'people', 'phone_number');
    $malformed = People::factory()->recycle([$this->user, $this->workspace])->create();
    $messy = People::factory()->recycle([$this->user, $this->workspace])->create();
    writeRawJsonValue($malformed->getKey(), $phone, ['number' => '+1 415-555-0100']);
    writeRawJsonValue($messy->getKey(), $phone, ['+1 415-555-0142']);
    $malformedRowId = DB::table('custom_field_values')->where('entity_id', $malformed->getKey())->where('custom_field_id', $phone->getKey())->value('id');
    Log::spy();

    $this->artisan('custom-fields:normalize-values', ['--force' => true])->assertSuccessful();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $context === [
            'value_id' => $malformedRowId,
            'field' => 'people.phone_number',
            'workspace_id' => $this->workspace->getKey(),
            'reason' => 'unexpected_shape',
        ])
        ->once();
    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $context === ['mode' => 'write', 'changed' => 1, 'skipped' => 1, 'national_phones' => 0, 'shared_domains' => 0])
        ->once();
});

it('never prints a stored value when a row fails to update', function (): void {
    $phone = WorkspaceCustomField::byCode($this->workspace->getKey(), 'people', 'phone_number');
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    writeRawJsonValue($person->getKey(), $phone, ['+1 415-555-0142']);
    DB::beforeExecuting(function (string $query, array $bindings): void {
        throw_if(str_starts_with($query, 'update "custom_field_values"'), RuntimeException::class, 'update failed for '.implode(' ', array_map(strval(...), $bindings)));
    });

    $this->artisan('custom-fields:normalize-values', ['--force' => true])
        ->doesntExpectOutputToContain('415-555-0142')
        ->doesntExpectOutputToContain('4155550142')
        ->expectsOutputToContain('RuntimeException, skipped.')
        ->assertSuccessful();
});

it('logs a row edited while the backfill runs as skipped', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $phone = WorkspaceCustomField::byCode($this->workspace->getKey(), 'people', 'phone_number');
    writeRawJsonValue($person->getKey(), $phone, ['+1 415-555-0100']);
    $edited = false;
    Log::spy();

    DB::listen(function (QueryExecuted $query) use (&$edited, $person, $phone): void {
        if ($edited || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, '"custom_field_values"') || ! in_array($phone->getKey(), $query->bindings, true)) {
            return;
        }

        $edited = true;
        DB::table('custom_field_values')->where('entity_id', $person->getKey())->where('custom_field_id', $phone->getKey())->update(['json_value' => json_encode(['+14155550199'])]);
    });

    $this->artisan('custom-fields:normalize-values', ['--force' => true])->assertSuccessful();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context['reason'] === 'changed_during_run')->once();
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $context['changed'] === 0 && $context['skipped'] === 1)->once();
});

it('logs a summary and no skipped row in report mode', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    writeRawJsonValue($person->getKey(), WorkspaceCustomField::byCode($this->workspace->getKey(), 'people', 'phone_number'), ['+1 415-555-0142']);
    Log::spy();

    $this->artisan('custom-fields:normalize-values')->assertSuccessful();

    Log::shouldNotHaveReceived('warning');
    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $context === ['mode' => 'report', 'changed' => 1, 'skipped' => 0, 'national_phones' => 0, 'shared_domains' => 0])
        ->once();
});
