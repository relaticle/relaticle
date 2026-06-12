<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Tools\BaseWriteDeleteTool;
use Relaticle\Chat\Tools\Company\CreateCompanyTool;
use Relaticle\Chat\Tools\Company\DeleteCompanyTool;
use Relaticle\Chat\Tools\People\CreatePersonTool;

mutates(BaseWriteDeleteTool::class);
mutates(DeleteCompanyTool::class);
mutates(CreatePersonTool::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->user->switchTeam($this->user->ownedTeams()->first());
    $this->actingAs($this->user);
});

it('DeleteCompanyTool does not include record ID in action card display fields', function (): void {
    $company = Company::factory()->for($this->user->currentTeam)->create(['name' => 'Acme']);

    /** @var DeleteCompanyTool $tool */
    $tool = app(DeleteCompanyTool::class);

    $tool->handle(new Request(['ids' => [$company->getKey()]]));

    $pending = PendingAction::query()
        ->where('user_id', $this->user->getKey())
        ->latest('created_at')
        ->firstOrFail();

    $labels = collect($pending->display_data['fields'])->pluck('label')->all();

    expect($labels)->not->toContain('ID');
});

it('DeleteCompanyTool returns the record ID in the LLM-facing JSON payload (internal use only)', function (): void {
    $company = Company::factory()->for($this->user->currentTeam)->create(['name' => 'Acme']);

    /** @var DeleteCompanyTool $tool */
    $tool = app(DeleteCompanyTool::class);

    $json = $tool->handle(new Request(['ids' => [$company->getKey()]]));

    $payload = json_decode($json, true);

    expect($payload['data']['ids'])->toContain($company->getKey());

    $pending = PendingAction::query()
        ->where('user_id', $this->user->getKey())
        ->latest('created_at')
        ->firstOrFail();

    $labels = collect($pending->display_data['fields'])->pluck('label')->all();

    expect($labels)->not->toContain('ID');
});

it('CreatePersonTool shows company name (not company ID) in action card display', function (): void {
    $company = Company::factory()->for($this->user->currentTeam)->create(['name' => 'Acme']);

    /** @var CreatePersonTool $tool */
    $tool = app(CreatePersonTool::class);

    $tool->handle(new Request([
        'records' => [['name' => 'Jane Doe', 'company_id' => $company->getKey()]],
    ]));

    $pending = PendingAction::query()
        ->where('user_id', $this->user->getKey())
        ->latest('created_at')
        ->firstOrFail();

    $fields = collect($pending->display_data['fields']);
    $labels = $fields->pluck('label')->all();

    expect($labels)->not->toContain('Company ID')
        ->and($labels)->toContain('Company');

    $companyField = $fields->firstWhere('label', 'Company');
    expect($companyField['value'])->toBe('Acme');
});

it('emits type hints on custom field display rows', function (): void {
    /** @var CreateCompanyTool $tool */
    $tool = app(CreateCompanyTool::class);

    $tool->handle(new Request([
        'records' => [[
            'name' => 'Typed Display Co',
            'custom_fields' => [
                'linkedin' => ['linkedin.com/company/typed-display'],
                'icp' => true,
            ],
        ]],
    ]));

    $pending = PendingAction::query()
        ->where('user_id', $this->user->getKey())
        ->latest('created_at')
        ->firstOrFail();

    $rows = collect($pending->display_data['fields']);

    expect($rows->firstWhere('label', 'LinkedIn')['type'])->toBe('link')
        ->and($rows->firstWhere('label', 'ICP')['type'])->toBe('boolean');
});
