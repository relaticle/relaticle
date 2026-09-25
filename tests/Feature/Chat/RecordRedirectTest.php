<?php

declare(strict_types=1);

use App\Filament\Pages\Workspace\CustomFields;
use App\Filament\Pages\Workspace\Members;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\NoteResource;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\TaskResource;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\EditAction;
use Illuminate\Support\Str;
use Relaticle\Chat\Http\Controllers\RecordRedirectController;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(RecordRedirectController::class);

dataset('mapped record types', [
    'company' => ['company', Company::class],
    'people' => ['people', People::class],
    'opportunity' => ['opportunity', Opportunity::class],
    'task' => ['task', Task::class],
    'note' => ['note', Note::class],
]);

it('redirects to the exact panel url for each mapped type', function (string $type, string $modelClass): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $record = $modelClass::factory()->for($workspace)->create();

    $expectedUrl = match ($type) {
        'company' => CompanyResource::getUrl('view', ['record' => $record->getKey()], panel: 'app', tenant: $workspace),
        'people' => PeopleResource::getUrl('view', ['record' => $record->getKey()], panel: 'app', tenant: $workspace),
        'opportunity' => OpportunityResource::getUrl('view', ['record' => $record->getKey()], panel: 'app', tenant: $workspace),
        'task' => TaskResource::getUrl('index', [
            'tableAction' => EditAction::getDefaultName(),
            'tableActionRecord' => $record->getKey(),
        ], panel: 'app', tenant: $workspace),
        'note' => NoteResource::getUrl('index', [
            'tableAction' => EditAction::getDefaultName(),
            'tableActionRecord' => $record->getKey(),
        ], panel: 'app', tenant: $workspace),
    };

    $this->actingAs($user)
        ->get("/r/{$type}/{$record->getKey()}")
        ->assertRedirect($expectedUrl);
})->with('mapped record types');

it("redirects to the record's own workspace panel when the record belongs to a non-current workspace", function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $currentWorkspace = $user->currentWorkspace;

    $otherWorkspace = Workspace::factory()->create(['user_id' => $user->getKey()]);
    $user->workspaces()->attach($otherWorkspace, ['role' => 'admin']);

    $company = Company::factory()->for($otherWorkspace)->create();

    $expectedUrl = CompanyResource::getUrl('view', ['record' => $company->getKey()], panel: 'app', tenant: $otherWorkspace);

    $this->actingAs($user)
        ->get("/r/company/{$company->getKey()}")
        ->assertRedirect($expectedUrl);

    expect($expectedUrl)->toContain($otherWorkspace->slug)
        ->and($expectedUrl)->not->toContain($currentWorkspace->slug);
});

it('shows a gone page for a soft deleted record in the user workspace', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $company = Company::factory()->for($workspace)->create();
    $company->delete();

    $this->actingAs($user)
        ->get("/r/company/{$company->getKey()}")
        ->assertStatus(410)
        ->assertSee(__('This record no longer exists'));
});

it('404s for a record outside every workspace the user belongs to', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $other = Company::factory()->create(); // unrelated workspace

    $this->actingAs($user)->get("/r/company/{$other->getKey()}")->assertNotFound();
});

it('still 404s a soft deleted record outside every workspace the user belongs to', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $other = Company::factory()->create(); // unrelated workspace
    $other->delete();

    $this->actingAs($user)->get("/r/company/{$other->getKey()}")->assertNotFound();
});

it('404s for an unknown type', function (): void {
    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user)->get('/r/wormhole/123')->assertNotFound();
});

it('404s for an authenticated request to a well-formed but nonexistent record id', function (): void {
    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user)->get('/r/company/'.(string) Str::ulid())->assertNotFound();
});

it('requires auth', function (): void {
    $this->get('/r/company/123')->assertRedirect(route('login'));
});

it('redirects to the management page for an own custom field', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    TenantContextService::setTenantId($user->currentWorkspace->getKey());

    $field = CustomField::factory()->create([
        config('custom-fields.database.column_names.tenant_foreign_key') => $user->currentWorkspace->getKey(),
        'entity_type' => 'people',
    ]);

    TenantContextService::setTenantId(null);

    $expectedUrl = CustomFields::getUrl(panel: 'app', tenant: $user->currentWorkspace).'?'.http_build_query([
        'currentEntityType' => 'people',
    ]);

    $this->actingAs($user)
        ->get("/r/custom_field/{$field->getKey()}")
        ->assertRedirect($expectedUrl);
});

it('redirects a workspace invitation reference straight to the Members page', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    $expectedUrl = Members::getUrl(panel: 'app', tenant: $workspace);

    $this->actingAs($user)
        ->get('/r/workspace_invitations/'.(string) Str::ulid())
        ->assertRedirect($expectedUrl);
});

it('404s for a custom field belonging to another workspace', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    TenantContextService::setTenantId($owner->currentWorkspace->getKey());

    $field = CustomField::factory()->create([
        config('custom-fields.database.column_names.tenant_foreign_key') => $owner->currentWorkspace->getKey(),
        'entity_type' => 'people',
    ]);

    TenantContextService::setTenantId(null);

    $stranger = User::factory()->withPersonalWorkspace()->create();

    $this->actingAs($stranger)->get("/r/custom_field/{$field->getKey()}")->assertNotFound();
});
