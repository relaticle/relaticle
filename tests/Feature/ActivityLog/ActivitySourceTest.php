<?php

declare(strict_types=1);

use App\Actions\Company\UpdateCompany;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Http\Middleware\SetCurrentSource;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\Company\UpdateCompanyTool;
use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\User;
use App\Support\CurrentSource;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;

mutates(CurrentSource::class, SetCurrentSource::class, RelaticleServer::class, PendingActionService::class);

beforeEach(function (): void {
    Bus::fake();
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);

    $this->company = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme']);
    Activity::withoutGlobalScopes()->delete();
});

/** @return list<string|null> */
function sourcesOfCompanyUpdates(Company $company): array
{
    return Activity::withoutGlobalScopes()
        ->where('subject_id', $company->getKey())
        ->where('event', 'updated')
        ->orderBy('id')
        ->get()
        ->map(fn (Activity $row): ?string => $row->properties[Activity::SOURCE_PROPERTY] ?? null)
        ->all();
}

function approveCompanyRenameInChat(User $user, Company $company, string $name): void
{
    $proposal = PendingAction::query()->create([
        'workspace_id' => $user->currentWorkspace->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => null,
        'action_class' => UpdateCompany::class,
        'operation' => PendingActionOperation::Update,
        'entity_type' => 'company',
        'action_data' => ['_record_id' => (string) $company->getKey(), '_model_class' => Company::class, 'name' => $name],
        'display_data' => ['title' => 'Update Company', 'summary' => "Rename to {$name}", 'fields' => []],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    resolve(PendingActionService::class)->approve($proposal, $user);
}

it('stamps each channel that edits one record with its own source', function (): void {
    livewire(ListCompanies::class)
        ->callAction(TestAction::make('edit')->table($this->company), data: ['name' => 'Via Panel'])
        ->assertHasNoActionErrors();

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateCompanyTool::class, ['id' => $this->company->getKey(), 'name' => 'Via Mcp'])
        ->assertOk();

    approveCompanyRenameInChat($this->user, $this->company, 'Via Chat');

    Sanctum::actingAs($this->user);
    $this->putJson("/api/v1/companies/{$this->company->getKey()}", ['name' => 'Via Api'])->assertOk();

    expect(sourcesOfCompanyUpdates($this->company))->toBe(['web', 'mcp', 'chat', 'api']);
});

it('stamps a delete through the api as api', function (): void {
    Sanctum::actingAs($this->user);

    $this->deleteJson("/api/v1/companies/{$this->company->getKey()}")->assertNoContent();

    $row = Activity::withoutGlobalScopes()
        ->where('subject_id', $this->company->getKey())
        ->where('event', 'deleted')
        ->sole();

    expect($row->properties[Activity::SOURCE_PROPERTY])->toBe('api');
});

it('stamps an mcp call that arrives over http', function (): void {
    Sanctum::actingAs($this->user, ['*']);

    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'update-company-tool',
            'arguments' => ['id' => $this->company->getKey(), 'name' => 'Over Http'],
        ],
    ])->assertOk();

    expect($this->company->refresh()->name)->toBe('Over Http')
        ->and(sourcesOfCompanyUpdates($this->company))->toBe(['mcp']);
});

it('stamps a write after a chat approval in the same request as web again', function (): void {
    approveCompanyRenameInChat($this->user, $this->company, 'Via Chat');

    $this->company->refresh()->update(['name' => 'By Hand']);

    expect(sourcesOfCompanyUpdates($this->company))->toBe(['chat', 'web']);
});
