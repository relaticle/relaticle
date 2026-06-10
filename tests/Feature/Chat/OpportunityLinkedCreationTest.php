<?php

declare(strict_types=1);

use App\Actions\Opportunity\CreateOpportunity;
use App\Actions\Opportunity\UpdateOpportunity;
use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Tools\Opportunity\CreateOpportunityTool;
use Relaticle\Chat\Tools\Opportunity\UpdateOpportunityTool;

mutates(CreateOpportunityTool::class);
mutates(UpdateOpportunityTool::class);
mutates(CreateOpportunity::class);
mutates(UpdateOpportunity::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    Auth::guard('web')->setUser($this->user);

    DB::table('agent_conversations')->insert([
        'id' => '019df800-4444-7000-8000-000000000001',
        'user_id' => (string) $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('CreateOpportunityTool exposes company_id and contact_id in the schema', function (): void {
    $tool = resolve(CreateOpportunityTool::class);
    $schema = $tool->schema(new JsonSchemaTypeFactory);

    expect($schema)
        ->toHaveKey('name')
        ->toHaveKey('company_id')
        ->toHaveKey('contact_id');
});

it('persists company_id and contact_id in the pending action data', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Acme']);
    $contact = People::factory()->for($this->team)->create(['name' => 'Angel']);

    $tool = resolve(CreateOpportunityTool::class);
    $tool->setConversationId('019df800-4444-7000-8000-000000000001');

    $tool->handle(new Request([
        'name' => 'Acme deal',
        'company_id' => (string) $company->id,
        'contact_id' => (string) $contact->id,
    ]));

    $pending = PendingAction::query()
        ->where('team_id', $this->team->getKey())
        ->where('entity_type', 'opportunity')
        ->latest()
        ->firstOrFail();

    expect($pending->action_data)
        ->toHaveKey('name', 'Acme deal')
        ->toHaveKey('company_id', (string) $company->id)
        ->toHaveKey('contact_id', (string) $contact->id);
});

it('approving an opportunity creates it linked to a company and contact', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Acme']);
    $contact = People::factory()->for($this->team)->create(['name' => 'Angel']);

    $opportunity = resolve(CreateOpportunity::class)->execute(
        $this->user,
        [
            'name' => 'Acme deal',
            'company_id' => (string) $company->id,
            'contact_id' => (string) $contact->id,
        ],
        CreationSource::CHAT,
    );

    expect($opportunity)->toBeInstanceOf(Opportunity::class);
    expect((string) $opportunity->company_id)->toBe((string) $company->id);
    expect((string) $opportunity->contact_id)->toBe((string) $contact->id);
});

it('rejects cross-tenant company_id at the action layer', function (): void {
    $other = User::factory()->withPersonalTeam()->create();
    $foreign = Company::factory()->for($other->currentTeam)->create(['name' => 'Mallory Co']);

    expect(fn () => resolve(CreateOpportunity::class)->execute(
        $this->user,
        ['name' => 'X', 'company_id' => (string) $foreign->id],
        CreationSource::CHAT,
    ))->toThrow(ValidationException::class);
});

it('rejects cross-tenant contact_id at the action layer', function (): void {
    $other = User::factory()->withPersonalTeam()->create();
    $foreign = People::factory()->for($other->currentTeam)->create(['name' => 'Mallory']);

    expect(fn () => resolve(CreateOpportunity::class)->execute(
        $this->user,
        ['name' => 'X', 'contact_id' => (string) $foreign->id],
        CreationSource::CHAT,
    ))->toThrow(ValidationException::class);
});

it('renders linked company and contact names in the create proposal display data', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Acme']);
    $contact = People::factory()->for($this->team)->create(['name' => 'Angel']);

    $tool = resolve(CreateOpportunityTool::class);
    $tool->setConversationId('019df800-4444-7000-8000-000000000001');

    $tool->handle(new Request([
        'name' => 'Acme deal',
        'company_id' => (string) $company->id,
        'contact_id' => (string) $contact->id,
    ]));

    $pending = PendingAction::query()
        ->where('team_id', $this->team->getKey())
        ->where('entity_type', 'opportunity')
        ->latest()
        ->firstOrFail();

    $fields = collect($pending->display_data['fields'] ?? []);
    $values = $fields->pluck('value')->all();

    expect(implode(' ', array_filter($values, 'is_string')))
        ->toContain('Acme')
        ->toContain('Angel');
});

it('UpdateOpportunityTool renders linked names in the update proposal display data', function (): void {
    $oldCompany = Company::factory()->for($this->team)->create(['name' => 'Old Co']);
    $newCompany = Company::factory()->for($this->team)->create(['name' => 'New Co']);
    $newContact = People::factory()->for($this->team)->create(['name' => 'Angel']);

    $opportunity = Opportunity::factory()
        ->for($this->team)
        ->create(['name' => 'Existing deal', 'company_id' => $oldCompany->id]);

    $tool = resolve(UpdateOpportunityTool::class);
    $tool->setConversationId('019df800-4444-7000-8000-000000000001');

    $tool->handle(new Request([
        'id' => (string) $opportunity->id,
        'company_id' => (string) $newCompany->id,
        'contact_id' => (string) $newContact->id,
    ]));

    $pending = PendingAction::query()
        ->where('team_id', $this->team->getKey())
        ->where('entity_type', 'opportunity')
        ->latest()
        ->firstOrFail();

    $fields = collect($pending->display_data['fields'] ?? []);
    $labels = $fields->pluck('label')->all();

    expect($labels)->toContain('Company');
    expect($labels)->toContain('Contact');

    $companyField = $fields->firstWhere('label', 'Company');
    $contactField = $fields->firstWhere('label', 'Contact');

    expect($companyField['old'] ?? '')->toBe('Old Co');
    expect($companyField['new'] ?? '')->toBe('New Co');
    expect($contactField['new'] ?? '')->toBe('Angel');
});
