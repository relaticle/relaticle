<?php

declare(strict_types=1);

use App\Actions\People\CreatePeople;
use App\Actions\People\UpdatePeople;
use App\Events\WorkspaceCreated;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\People\CreatePeopleTool;
use App\Mcp\Tools\People\UpdatePeopleTool;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Relaticle\EmailIntegration\Actions\LinkPersonCompanyFromEmails;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\RowMatchAction;
use Relaticle\ImportWizard\Store\ImportStore;
use Tests\Helpers\ImportExecutionFixture;

mutates(
    CreatePeople::class,
    UpdatePeople::class,
    LinkPersonCompanyFromEmails::class,
);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
    $this->workspace->update(['auto_create_companies' => true]);
});

afterEach(function (): void {
    if (isset($this->import)) {
        ImportStore::load($this->import->id)?->destroy();
        $this->import->delete();
    }
});

it('creates and links a company from a work email on API create', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/people', [
        'name' => 'Jane',
        'custom_fields' => ['emails' => ['jane@acme.com']],
    ])->assertCreated();

    $person = People::query()->where('name', 'Jane')->firstOrFail();

    expect($person->company_id)->not->toBeNull();
    $this->assertDatabaseHas('companies', [
        'workspace_id' => $this->workspace->id,
        'name' => 'Acme',
    ]);
});

it('does not create a company from a public domain alone', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/people', [
        'name' => 'Jane',
        'custom_fields' => ['emails' => ['jane@gmail.com']],
    ])->assertCreated();

    $person = People::query()->where('name', 'Jane')->firstOrFail();

    expect($person->company_id)->toBeNull();
    $this->assertDatabaseMissing('companies', [
        'workspace_id' => $this->workspace->id,
        'name' => 'Gmail',
    ]);
});

it('uses the work email when the person also has a public address', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/people', [
        'name' => 'Jane',
        'custom_fields' => ['emails' => ['jane@gmail.com', 'jane@acme.com']],
    ])->assertCreated();

    $person = People::query()->where('name', 'Jane')->firstOrFail();

    expect($person->company_id)->not->toBeNull();
    $this->assertDatabaseHas('companies', [
        'workspace_id' => $this->workspace->id,
        'name' => 'Acme',
    ]);
});

it('links an existing company when auto_create_companies is false', function (): void {
    $this->workspace->update(['auto_create_companies' => false]);

    $domainsField = CustomField::query()
        ->where('tenant_id', $this->workspace->id)
        ->where('entity_type', 'company')
        ->where('code', 'domains')
        ->firstOrFail();

    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme']);
    $company->saveCustomFieldValue($domainsField, 'www.acme.com', $this->workspace);

    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/people', [
        'name' => 'Jane',
        'custom_fields' => ['emails' => ['jane@acme.com']],
    ])->assertCreated();

    $person = People::query()->where('name', 'Jane')->firstOrFail();

    expect($person->company_id)->toBe($company->getKey());
    expect(Company::query()->where('workspace_id', $this->workspace->id)->count())->toBe(1);
});

it('does not create a company when the toggle is off and none exists', function (): void {
    $this->workspace->update(['auto_create_companies' => false]);

    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/people', [
        'name' => 'Jane',
        'custom_fields' => ['emails' => ['jane@newco.com']],
    ])->assertCreated();

    $person = People::query()->where('name', 'Jane')->firstOrFail();

    expect($person->company_id)->toBeNull();
    $this->assertDatabaseMissing('companies', [
        'workspace_id' => $this->workspace->id,
        'name' => 'Newco',
    ]);
});

it('does not overwrite an explicit company_id', function (): void {
    $chosen = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Chosen']);

    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/people', [
        'name' => 'Jane',
        'company_id' => $chosen->getKey(),
        'custom_fields' => ['emails' => ['jane@acme.com']],
    ])->assertCreated();

    $person = People::query()->where('name', 'Jane')->firstOrFail();

    expect($person->company_id)->toBe($chosen->getKey());
    $this->assertDatabaseMissing('companies', [
        'workspace_id' => $this->workspace->id,
        'name' => 'Acme',
    ]);
});

it('links a company when a work email is added later via API', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/people', ['name' => 'Jane'])->assertCreated();

    $person = People::query()->where('name', 'Jane')->firstOrFail();

    expect($person->company_id)->toBeNull();

    $this->putJson("/api/v1/people/{$person->id}", [
        'custom_fields' => ['emails' => ['jane@acme.com']],
    ])->assertOk();

    expect($person->fresh()->company_id)->not->toBeNull();
});

it('creates and links a company from a work email via MCP', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(CreatePeopleTool::class, [
            'name' => 'Mcp Jane',
            'custom_fields' => ['emails' => ['jane@mcpco.com']],
        ])
        ->assertOk();

    $person = People::query()->where('name', 'Mcp Jane')->firstOrFail();

    expect($person->company_id)->not->toBeNull();
    $this->assertDatabaseHas('companies', [
        'workspace_id' => $this->workspace->id,
        'name' => 'Mcpco',
    ]);
});

it('links a company when a work email is added later via MCP', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Mcp Update Jane']);

    RelaticleServer::actingAs($this->user)
        ->tool(UpdatePeopleTool::class, [
            'id' => $person->id,
            'custom_fields' => ['emails' => ['jane@mcpupdate.com']],
        ])
        ->assertOk();

    expect($person->fresh()->company_id)->not->toBeNull();
});

it('creates and links a company from a work email on panel create', function (): void {
    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);

    livewire(ListPeople::class)
        ->callAction('create', data: [
            'name' => 'Panel Jane',
            'custom_fields' => ['emails' => ['jane@panelco.com']],
        ])
        ->assertHasNoActionErrors();

    $person = People::query()->where('name', 'Panel Jane')->firstOrFail();

    expect($person->company_id)->not->toBeNull();
    $this->assertDatabaseHas('companies', [
        'workspace_id' => $this->workspace->id,
        'name' => 'Panelco',
    ]);
});

it('links a company when a work email is added later on the panel', function (): void {
    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);

    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Panel Update Jane']);

    livewire(ListPeople::class)
        ->callAction(TestAction::make('edit')->table($person), data: [
            'name' => 'Panel Update Jane',
            'custom_fields' => ['emails' => ['jane@paneledit.com']],
        ])
        ->assertHasNoActionErrors();

    expect($person->fresh()->company_id)->not->toBeNull();
    $this->assertDatabaseHas('companies', [
        'workspace_id' => $this->workspace->id,
        'name' => 'Paneledit',
    ]);
});

it('creates and links a company from a work email on CSV import', function (): void {
    Event::fake()->except([WorkspaceCreated::class]);
    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);

    ImportExecutionFixture::readyStore($this, ['Name', 'Email'], [
        ImportExecutionFixture::row(2, ['Name' => 'Import Jane', 'Email' => 'jane@importco.com'], [
            'match_action' => RowMatchAction::Create->value,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::query()
        ->where('workspace_id', $this->workspace->id)
        ->where('name', 'Import Jane')
        ->firstOrFail();

    expect($person->company_id)->not->toBeNull();
    $this->assertDatabaseHas('companies', [
        'workspace_id' => $this->workspace->id,
        'name' => 'Importco',
    ]);
});
