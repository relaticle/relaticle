<?php

declare(strict_types=1);

use App\Filament\Pages\Team\CustomFields;
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
use App\Models\Team;
use App\Models\User;
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
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $record = $modelClass::factory()->for($team)->create();

    $expectedUrl = match ($type) {
        'company' => CompanyResource::getUrl('view', ['record' => $record->getKey()], panel: 'app', tenant: $team),
        'people' => PeopleResource::getUrl('view', ['record' => $record->getKey()], panel: 'app', tenant: $team),
        'opportunity' => OpportunityResource::getUrl('view', ['record' => $record->getKey()], panel: 'app', tenant: $team),
        'task' => TaskResource::getUrl('index', [
            'tableAction' => EditAction::getDefaultName(),
            'tableActionRecord' => $record->getKey(),
        ], panel: 'app', tenant: $team),
        'note' => NoteResource::getUrl('index', [
            'tableAction' => EditAction::getDefaultName(),
            'tableActionRecord' => $record->getKey(),
        ], panel: 'app', tenant: $team),
    };

    $this->actingAs($user)
        ->get("/r/{$type}/{$record->getKey()}")
        ->assertRedirect($expectedUrl);
})->with('mapped record types');

it("redirects to the record's own team panel when the record belongs to a non-current team", function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $currentTeam = $user->currentTeam;

    $otherTeam = Team::factory()->create(['user_id' => $user->getKey()]);
    $user->teams()->attach($otherTeam, ['role' => 'admin']);

    $company = Company::factory()->for($otherTeam)->create();

    $expectedUrl = CompanyResource::getUrl('view', ['record' => $company->getKey()], panel: 'app', tenant: $otherTeam);

    $this->actingAs($user)
        ->get("/r/company/{$company->getKey()}")
        ->assertRedirect($expectedUrl);

    expect($expectedUrl)->toContain($otherTeam->slug)
        ->and($expectedUrl)->not->toContain($currentTeam->slug);
});

it('404s for a record outside every team the user belongs to', function (): void {
    $user = User::factory()->withTeam()->create();
    $other = Company::factory()->create(); // unrelated team

    $this->actingAs($user)->get("/r/company/{$other->getKey()}")->assertNotFound();
});

it('404s for an unknown type', function (): void {
    $user = User::factory()->withTeam()->create();

    $this->actingAs($user)->get('/r/wormhole/123')->assertNotFound();
});

it('404s for an authenticated request to a well-formed but nonexistent record id', function (): void {
    $user = User::factory()->withTeam()->create();

    $this->actingAs($user)->get('/r/company/'.(string) Str::ulid())->assertNotFound();
});

it('requires auth', function (): void {
    $this->get('/r/company/123')->assertRedirect(route('login'));
});

it('redirects to the management page for an own custom field', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    TenantContextService::setTenantId($user->currentTeam->getKey());

    $field = CustomField::factory()->create([
        config('custom-fields.database.column_names.tenant_foreign_key') => $user->currentTeam->getKey(),
        'entity_type' => 'people',
    ]);

    TenantContextService::setTenantId(null);

    $expectedUrl = CustomFields::getUrl(panel: 'app', tenant: $user->currentTeam).'?'.http_build_query([
        'currentEntityType' => 'people',
    ]);

    $this->actingAs($user)
        ->get("/r/custom_field/{$field->getKey()}")
        ->assertRedirect($expectedUrl);
});

it('404s for a custom field belonging to another team', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    TenantContextService::setTenantId($owner->currentTeam->getKey());

    $field = CustomField::factory()->create([
        config('custom-fields.database.column_names.tenant_foreign_key') => $owner->currentTeam->getKey(),
        'entity_type' => 'people',
    ]);

    TenantContextService::setTenantId(null);

    $stranger = User::factory()->withPersonalTeam()->create();

    $this->actingAs($stranger)->get("/r/custom_field/{$field->getKey()}")->assertNotFound();
});
