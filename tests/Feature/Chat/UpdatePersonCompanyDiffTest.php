<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Tools\People\UpdatePersonTool;

mutates(UpdatePersonTool::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    Auth::guard('web')->setUser($this->user);

    DB::table('agent_conversations')->insert([
        'id' => '019df800-4444-7000-8000-000000000099',
        'user_id' => (string) $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('proposal display_data contains a Company field when company_id is changed', function (): void {
    $oldCompany = Company::factory()->for($this->team)->create(['name' => 'Old Corp']);
    $newCompany = Company::factory()->for($this->team)->create(['name' => 'New Corp']);
    $person = People::factory()->for($this->team)->for($oldCompany)->create(['name' => 'Jane Doe']);

    $tool = resolve(UpdatePersonTool::class);
    $tool->setConversationId('019df800-4444-7000-8000-000000000099');

    $tool->handle(new Request([
        'id' => (string) $person->getKey(),
        'company_id' => (string) $newCompany->getKey(),
    ]));

    $pending = PendingAction::query()
        ->where('team_id', $this->team->getKey())
        ->latest()
        ->firstOrFail();

    $fields = $pending->display_data['fields'];
    $labels = array_column($fields, 'label');

    expect($labels)->toContain('Company');

    $companyField = $fields[array_search('Company', $labels)];

    expect($companyField['old'])->toBe('Old Corp')
        ->and($companyField['new'])->toBe('New Corp');
});

it('proposal display_data does not include Company field when company_id is not changed', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Same Corp']);
    $person = People::factory()->for($this->team)->for($company)->create(['name' => 'Bob Smith']);

    $tool = resolve(UpdatePersonTool::class);
    $tool->setConversationId('019df800-4444-7000-8000-000000000099');

    $tool->handle(new Request([
        'id' => (string) $person->getKey(),
        'name' => 'Robert Smith',
    ]));

    $pending = PendingAction::query()
        ->where('team_id', $this->team->getKey())
        ->latest()
        ->firstOrFail();

    $labels = array_column($pending->display_data['fields'], 'label');

    expect($labels)->not->toContain('Company')
        ->and($labels)->toContain('Name');
});

it('shows empty old company when person had no company and a new one is assigned', function (): void {
    $newCompany = Company::factory()->for($this->team)->create(['name' => 'First Corp']);
    $person = People::factory()->for($this->team)->create([
        'name' => 'No Company Person',
        'company_id' => null,
    ]);

    $tool = resolve(UpdatePersonTool::class);
    $tool->setConversationId('019df800-4444-7000-8000-000000000099');

    $tool->handle(new Request([
        'id' => (string) $person->getKey(),
        'company_id' => (string) $newCompany->getKey(),
    ]));

    $pending = PendingAction::query()
        ->where('team_id', $this->team->getKey())
        ->latest()
        ->firstOrFail();

    $fields = $pending->display_data['fields'];
    $companyField = $fields[array_search('Company', array_column($fields, 'label'))];

    expect($companyField['old'])->toBe('')
        ->and($companyField['new'])->toBe('First Corp');
});
