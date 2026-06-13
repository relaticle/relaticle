<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\CustomField;
use App\Models\User;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Services\Tools\ProposalDisplayBuilder;

mutates(ProposalDisplayBuilder::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    $this->user = User::factory()->withPersonalTeam()->create();
});

it('builds company display with title, name row, and custom link rows', function (): void {
    $linkedinField = CustomField::query()
        ->where('tenant_id', $this->user->currentTeam->getKey())
        ->where('entity_type', 'company')
        ->where('code', 'linkedin')
        ->first();

    $record = [
        'name' => 'Acme Corp',
        'account_owner_id' => null,
        'custom_fields' => $linkedinField !== null
            ? ['linkedin' => ['https://linkedin.com/company/acme']]
            : [],
    ];

    /** @var ProposalDisplayBuilder $builder */
    $builder = resolve(ProposalDisplayBuilder::class);
    $display = $builder->build($this->user, 'company', $record, []);

    expect($display['title'])->toBe('Create Company')
        ->and($display['summary'])->toBe('Create company "Acme Corp"')
        ->and($display['fields'])->toBeArray();

    $nameRow = collect($display['fields'])->firstWhere('label', 'Name');
    expect($nameRow)->not->toBeNull()
        ->and($nameRow['value'])->toBe('Acme Corp');

    if ($linkedinField !== null) {
        $linkedinRow = collect($display['fields'])->firstWhere('label', 'LinkedIn');
        expect($linkedinRow)->not->toBeNull()
            ->and($linkedinRow)->toHaveKey('type')
            ->and($linkedinRow['type'])->toBe('link');
    }
});

it('carries forward read-only core rows not owned by the builder', function (): void {
    $record = [
        'name' => 'Jane Doe',
        'custom_fields' => [],
    ];

    $existingFields = [
        ['label' => 'Company', 'value' => 'Acme'],
    ];

    /** @var ProposalDisplayBuilder $builder */
    $builder = resolve(ProposalDisplayBuilder::class);
    $display = $builder->build($this->user, 'people', $record, $existingFields);

    $labels = collect($display['fields'])->pluck('label')->all();

    expect($labels)->toContain('Name')
        ->and($labels)->toContain('Company');
});

it('does not duplicate custom rows when existingFields already has a type-bearing row', function (): void {
    $record = [
        'name' => 'Acme Corp',
        'custom_fields' => [],
    ];

    $existingFields = [
        ['label' => 'LinkedIn', 'type' => 'link', 'new' => 'https://linkedin.com/company/acme'],
    ];

    /** @var ProposalDisplayBuilder $builder */
    $builder = resolve(ProposalDisplayBuilder::class);
    $display = $builder->build($this->user, 'company', $record, $existingFields);

    $linkedinRows = collect($display['fields'])->filter(fn (array $r): bool => ($r['label'] ?? '') === 'LinkedIn')->values()->all();

    expect($linkedinRows)->toHaveCount(0);
});
