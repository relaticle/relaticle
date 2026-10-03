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

function workspaceSystemField(string $workspaceId, string $entityType, string $code): CustomField
{
    return CustomField::query()->withoutGlobalScopes()->where('tenant_id', $workspaceId)->where('entity_type', $entityType)->where('code', $code)->firstOrFail();
}

it('reports what it would change and writes nothing without force', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $phone = workspaceSystemField($this->workspace->getKey(), 'people', 'phone_number');
    writeRawJsonValue($person->getKey(), $phone, ['+1 415-555-0100', '555-123-4567']);

    $this->artisan('custom-fields:normalize-values')
        ->expectsOutputToContain('1 value(s) would change')
        ->expectsOutputToContain('1 national phone number(s) have no country code')
        ->assertSuccessful();

    expect(readRawJsonValue($person->getKey(), $phone))->toBe(['+1 415-555-0100', '555-123-4567']);
});

it('normalizes phones and domains with force and changes nothing on a second run', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $phone = workspaceSystemField($this->workspace->getKey(), 'people', 'phone_number');
    $domains = workspaceSystemField($this->workspace->getKey(), 'company', 'domains');
    writeRawJsonValue($person->getKey(), $phone, ['+1 415-555-0100']);
    writeRawJsonValue($company->getKey(), $domains, ['https://www.Acme.com/', 'acme.com']);

    $this->artisan('custom-fields:normalize-values', ['--force' => true])->assertSuccessful();

    expect(readRawJsonValue($person->getKey(), $phone))->toBe(['+14155550100'])
        ->and(readRawJsonValue($company->getKey(), $domains))->toBe(['acme.com']);

    $this->artisan('custom-fields:normalize-values', ['--force' => true])
        ->expectsOutputToContain('0 value(s) changed')
        ->assertSuccessful();
});

it('reports domains two companies share after normalization', function (): void {
    $first = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $second = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $domains = workspaceSystemField($this->workspace->getKey(), 'company', 'domains');
    writeRawJsonValue($first->getKey(), $domains, ['https://acme.com']);
    writeRawJsonValue($second->getKey(), $domains, ['acme.com']);

    $this->artisan('custom-fields:normalize-values')
        ->expectsOutputToContain("Workspace {$this->workspace->getKey()}: acme.com is shared by 2 companies")
        ->assertSuccessful();
});
