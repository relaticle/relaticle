<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\CustomField;
use App\Models\User;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Services\Tools\ProposalFieldSchemaDescriber;

mutates(ProposalFieldSchemaDescriber::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
});

function describerFor(): ProposalFieldSchemaDescriber
{
    return resolve(ProposalFieldSchemaDescriber::class);
}

/**
 * @param  list<array<string, mixed>>  $fields
 * @return array<string, mixed>|null
 */
function fieldByCode(array $fields, string $code): ?array
{
    foreach ($fields as $field) {
        if (($field['code'] ?? null) === $code) {
            return $field;
        }
    }

    return null;
}

it('describes company core name and account_owner_id select with member options', function (): void {
    $record = ['name' => 'Acme Corp', 'account_owner_id' => null];

    $fields = describerFor()->describe($this->user, 'company', $record);

    $name = fieldByCode($fields, 'name');
    expect($name)->not->toBeNull()
        ->and($name['label'])->toBe('Name')
        ->and($name['kind'])->toBe('text')
        ->and($name['value'])->toBe('Acme Corp')
        ->and($name['required'])->toBeTrue();

    $owner = fieldByCode($fields, 'account_owner_id');
    expect($owner)->not->toBeNull()
        ->and($owner['label'])->toBe('Account Owner')
        ->and($owner['kind'])->toBe('select')
        ->and($owner['value'])->toBeNull()
        ->and($owner['required'])->toBeFalse()
        ->and($owner['options'])->toBeArray()
        ->and($owner['options'])->not->toBeEmpty();

    $ownerIds = array_map(fn (array $o): string => $o['id'], $owner['options']);
    expect($ownerIds)->toContain((string) $this->user->getKey());
});

it('describes a company custom link field with kind link and a raw array value', function (): void {
    $linkedin = CustomField::query()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'company')
        ->where('code', 'linkedin')
        ->first();

    expect($linkedin)->not->toBeNull('seeded company linkedin field is required for this test');

    $record = [
        'name' => 'Acme Corp',
        'custom_fields' => ['linkedin' => ['https://linkedin.com/company/acme']],
    ];

    $fields = describerFor()->describe($this->user, 'company', $record);

    $linkedinField = fieldByCode($fields, 'linkedin');
    expect($linkedinField)->not->toBeNull()
        ->and($linkedinField['label'])->toBe('LinkedIn')
        ->and($linkedinField['kind'])->toBe('link')
        ->and($linkedinField['value'])->toBe(['https://linkedin.com/company/acme'])
        ->and($linkedinField['required'])->toBeFalse()
        ->and($linkedinField)->not->toHaveKey('options');
});

it('describes a task single-choice status field with options and the raw id value', function (): void {
    $status = CustomField::query()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->with('options')
        ->first();

    expect($status)->not->toBeNull('seeded task status field is required for this test');

    $inProgress = $status->options->firstWhere('name', 'In progress');
    expect($inProgress)->not->toBeNull();

    $record = [
        'title' => 'Ship it',
        'custom_fields' => ['status' => (string) $inProgress->id],
    ];

    $fields = describerFor()->describe($this->user, 'task', $record);

    $title = fieldByCode($fields, 'title');
    expect($title)->not->toBeNull()
        ->and($title['label'])->toBe('Title')
        ->and($title['kind'])->toBe('text');

    $statusField = fieldByCode($fields, 'status');
    expect($statusField)->not->toBeNull()
        ->and($statusField['kind'])->toBe('select')
        ->and($statusField['value'])->toBe((string) $inProgress->id)
        ->and($statusField['options'])->toBeArray();

    $optionLabels = array_map(fn (array $o): string => $o['label'], $statusField['options']);
    expect($optionLabels)->toContain('To do')
        ->and($optionLabels)->toContain('In progress')
        ->and($optionLabels)->toContain('Done');

    $inProgressOption = collect($statusField['options'])->firstWhere('label', 'In progress');
    expect($inProgressOption['id'])->toBe((string) $inProgress->id);
});

it('omits deferred record-link and assignee core fields', function (): void {
    $record = [
        'name' => 'Acme Corp',
        'account_owner_id' => null,
        'company_id' => '123',
        'contact_id' => '456',
        'people_ids' => ['7'],
        'company_ids' => ['8'],
        'opportunity_ids' => ['9'],
        'assignee_ids' => ['10'],
    ];

    $fields = describerFor()->describe($this->user, 'company', $record);

    $codes = array_map(fn (array $f): string => $f['code'], $fields);

    expect($codes)->not->toContain('company_id')
        ->and($codes)->not->toContain('contact_id')
        ->and($codes)->not->toContain('people_ids')
        ->and($codes)->not->toContain('company_ids')
        ->and($codes)->not->toContain('opportunity_ids')
        ->and($codes)->not->toContain('assignee_ids');
});
