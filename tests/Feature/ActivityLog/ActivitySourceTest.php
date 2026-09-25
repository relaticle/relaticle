<?php

declare(strict_types=1);

use App\Actions\Company\UpdateCompany;
use App\Enums\CreationSource;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Http\Middleware\SetCurrentSource;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\Company\UpdateCompanyTool;
use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\Concerns\HasCreator;
use App\Models\User;
use App\Support\ActivityLog\MergedActivityRenderer;
use App\Support\CurrentSource;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;

mutates(CurrentSource::class, SetCurrentSource::class, RelaticleServer::class, PendingActionService::class, HasCreator::class, MergedActivityRenderer::class);

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

    Sanctum::actingAs($this->user);
    $this->putJson("/api/v1/companies/{$this->company->getKey()}", ['name' => 'Via Api'])->assertOk();

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateCompanyTool::class, ['id' => $this->company->getKey(), 'name' => 'Via Mcp'])
        ->assertOk();

    approveCompanyRenameInChat($this->user, $this->company, 'Via Chat');

    expect(sourcesOfCompanyUpdates($this->company))->toBe(['web', 'api', 'mcp', 'chat']);
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

it('stamps a panel write after an api request in the same process as web', function (): void {
    Sanctum::actingAs($this->user);
    $this->putJson("/api/v1/companies/{$this->company->getKey()}", ['name' => 'Via Api'])->assertOk();

    $this->actingAs($this->user, 'web');
    livewire(ListCompanies::class)
        ->callAction(TestAction::make('edit')->table($this->company), data: ['name' => 'Via Panel'])
        ->assertHasNoActionErrors();

    expect(sourcesOfCompanyUpdates($this->company))->toBe(['api', 'web']);
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

it('stamps each item of a batch chat approval as chat', function (): void {
    $secondCompany = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Globex']);

    $proposal = PendingAction::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => null,
        'action_class' => UpdateCompany::class,
        'operation' => PendingActionOperation::Update,
        'entity_type' => 'company',
        'action_data' => [
            '_batch' => true,
            'records' => [
                ['_record_id' => (string) $this->company->getKey(), '_model_class' => Company::class, 'name' => 'Acme Batch'],
                ['_record_id' => (string) $secondCompany->getKey(), '_model_class' => Company::class, 'name' => 'Globex Batch'],
            ],
        ],
        'display_data' => [
            'title' => 'Update 2 companies',
            'summary' => 'Update 2 companies',
            'items' => [
                ['summary' => 'Update company "Acme"', 'fields' => []],
                ['summary' => 'Update company "Globex"', 'fields' => []],
            ],
        ],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    resolve(PendingActionService::class)->approveItem($proposal, $this->user, 0);
    resolve(PendingActionService::class)->approveItem($proposal, $this->user, 1);

    expect(sourcesOfCompanyUpdates($this->company))->toBe(['chat'])
        ->and(sourcesOfCompanyUpdates($secondCompany))->toBe(['chat']);
});

it('records the channel a record was created through, unless the writer states one', function (): void {
    $posted = CurrentSource::during(CreationSource::API, fn (): Company => Company::factory()->for($this->workspace)->create());
    $stated = CurrentSource::during(CreationSource::API, fn (): Company => Company::factory()->for($this->workspace)->create(['creation_source' => CreationSource::SYSTEM]));
    $typed = Company::factory()->for($this->workspace)->create();

    expect($posted->creation_source)->toBe(CreationSource::API)
        ->and($stated->creation_source)->toBe(CreationSource::SYSTEM)
        ->and($typed->creation_source)->toBe(CreationSource::WEB);
});

it('keeps the stored creation source of a record restored from serialization under another channel', function (): void {
    $stored = $this->company->fresh();

    $restored = CurrentSource::during(CreationSource::API, fn (): Company => unserialize(serialize($stored)));

    expect($restored->creation_source)->toBe(CreationSource::WEB)
        ->and($restored->isDirty())->toBeFalse();
});

it('adds no creation source to a partially loaded record restored under another channel', function (): void {
    $partial = Company::query()->select(['id', 'name', 'workspace_id'])->findOrFail($this->company->getKey());

    $restored = CurrentSource::during(CreationSource::API, fn (): Company => unserialize(serialize($partial)));

    expect($restored->getAttributes())->not->toHaveKey('creation_source')
        ->and($restored->isDirty())->toBeFalse();
});

it('keeps writes to one record under two channels in one request as separate saves', function (): void {
    CurrentSource::during(CreationSource::API, fn (): bool => $this->company->update(['name' => 'Posted']));
    $this->company->update(['name' => 'Typed']);

    $batches = Activity::withoutGlobalScopes()->where('subject_id', $this->company->getKey())->pluck('batch_uuid');

    expect($batches->unique())->toHaveCount(2)
        ->and($this->company->timeline()->get())->toHaveCount(2);
});

it('names the channel of an api change in the record timeline', function (): void {
    CurrentSource::during(CreationSource::API, fn (): bool => $this->company->update(['name' => 'Posted']));

    $html = (new MergedActivityRenderer)->render($this->company->timeline()->get()->first())->render();

    expect($html)->toContain(__('workspaces.activity.via_source', ['source' => CreationSource::API->getLabel()]));
});

it('leaves the channel line off web and legacy timeline entries', function (): void {
    $this->company->update(['name' => 'Typed']);

    $webHtml = (new MergedActivityRenderer)->render($this->company->timeline()->get()->first())->render();

    Activity::withoutGlobalScopes()->where('subject_id', $this->company->getKey())->update(['properties' => '{}']);

    $legacyHtml = (new MergedActivityRenderer)->render($this->company->timeline()->get()->first())->render();

    expect($webHtml)->not->toContain('Via ')
        ->and($legacyHtml)->not->toContain('Via ');
});
