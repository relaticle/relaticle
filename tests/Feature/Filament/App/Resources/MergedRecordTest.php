<?php

declare(strict_types=1);

use App\Filament\Concerns\HasRecordPageLayout;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use App\Policies\CompanyPolicy;
use App\Policies\PeoplePolicy;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Relaticle\Documentation\Support\DocUrl;

mutates(CompanyResource::class, PeopleResource::class, OpportunityResource::class, HasRecordPageLayout::class, CompanyPolicy::class, PeoplePolicy::class, Company::class, People::class);

dataset('merge-capable entities', [
    'company' => [Company::class, CompanyResource::class, ListCompanies::class],
    'person' => [People::class, PeopleResource::class, ListPeople::class],
]);

dataset('deletable entities', [
    'company' => [Company::class, CompanyResource::class, ListCompanies::class, ViewCompany::class],
    'person' => [People::class, PeopleResource::class, ListPeople::class, ViewPeople::class],
    'opportunity' => [Opportunity::class, OpportunityResource::class, ListOpportunities::class, ViewOpportunity::class],
]);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);

    openOpportunitiesOnTheList($this->user, $this->workspace);
});

function mergedAwayRecord(string $model, User $user, string $name): Model
{
    $target = $model::factory()->recycle([$user, $user->currentWorkspace])->create(['name' => $name.' Result']);
    $record = $model::factory()->recycle([$user, $user->currentWorkspace])->create(['name' => $name]);
    $record->delete();
    $model::withTrashed()->whereKey($record->getKey())->update(['merged_into_id' => $target->getKey()]);

    return $model::withTrashed()->findOrFail($record->getKey());
}

function deletedOnlyRecord(string $model, User $user, string $name): Model
{
    $record = $model::factory()->recycle([$user, $user->currentWorkspace])->create(['name' => $name]);
    $record->delete();

    return $record;
}

it('keeps a merged record out of the trashed filter and keeps a deleted one', function (string $model, string $resource, string $page): void {
    $merged = mergedAwayRecord($model, $this->user, 'Merged Away');
    $deleted = deletedOnlyRecord($model, $this->user, 'Plainly Deleted');

    foreach ([true, false] as $trashed) {
        livewire($page)
            ->filterTable('trashed', $trashed)
            ->assertCanSeeTableRecords([$deleted])
            ->assertCanNotSeeTableRecords([$merged]);
    }
})->with('merge-capable entities');

it('finds no merged record in global search', function (string $model, string $resource): void {
    mergedAwayRecord($model, $this->user, 'Searchable Original');

    $names = $resource::getGlobalSearchResults('Searchable Original')
        ->map(fn ($result): string => strip_tags($result->title->toHtml()))
        ->all();

    expect($names)->toHaveCount(1)->and($names[0])->toContain('Searchable Original Result');
})->with('merge-capable entities');

it('cannot restore a merged record from the trashed list', function (string $model, string $resource, string $page): void {
    $merged = mergedAwayRecord($model, $this->user, 'Merged Away');
    $deleted = deletedOnlyRecord($model, $this->user, 'Plainly Deleted');

    livewire($page)
        ->filterTable('trashed', false)
        ->selectTableRecords([$merged, $deleted])
        ->callAction([['name' => 'restore', 'context' => ['table' => true, 'bulk' => true]]]);

    expect($model::withTrashed()->findOrFail($merged->getKey())->trashed())->toBeTrue()
        ->and($model::withTrashed()->findOrFail($deleted->getKey())->trashed())->toBeFalse();
})->with('merge-capable entities');

it('refuses to restore a merged record through the model', function (string $model): void {
    $merged = mergedAwayRecord($model, $this->user, 'Merged Away');

    expect($merged->restore())->toBeFalse()
        ->and($model::withTrashed()->findOrFail($merged->getKey())->trashed())->toBeTrue();
})->with('merge-capable entities');

it('refuses every write ability on a merged record and allows it on a plain one', function (string $model): void {
    $merged = mergedAwayRecord($model, $this->user, 'Merged Away');
    $plain = $model::factory()->recycle([$this->user, $this->workspace])->create();

    foreach (['update', 'delete', 'restore', 'forceDelete'] as $ability) {
        expect($this->user->can($ability, $merged))->toBeFalse("{$ability} on merged")
            ->and($this->user->can($ability, $plain))->toBeTrue("{$ability} on plain");
    }

    expect($this->user->can('view', $merged))->toBeTrue();
})->with('merge-capable entities');

it('still resolves the old URL of a merged record', function (string $model, string $resource): void {
    $merged = mergedAwayRecord($model, $this->user, 'Merged Away');

    expect($resource::resolveRecordRouteBinding($merged->getKey())?->getKey())->toBe($merged->getKey());

    $this->get($resource::getUrl('view', ['record' => $merged]))->assertOk();
})->with('merge-capable entities');

it('shows the merged notice with no record data on a merged record page', function (string $model, string $resource, string $list, string $page): void {
    $merged = mergedAwayRecord($model, $this->user, 'Merged Away');

    $this->get($resource::getUrl('view', ['record' => $merged]))
        ->assertOk()
        ->assertSee('This record was merged')
        ->assertSee('It was merged with another record and no longer exists on its own.')
        ->assertDontSee('Merged Away')
        ->assertSee($resource::getUrl('view', ['record' => $merged->merged_into_id]), false)
        ->assertSee(DocUrl::helpPage('records', 'merge-duplicate-records'), false);

    livewire($page, ['record' => $merged->getKey()])
        ->assertSee('Go to record')
        ->assertSee('How merging works')
        ->assertDontSee(['Merged Away', 'Overview', 'Merge record', 'Edit', 'New note', 'New task', 'Copy page URL']);
})->with([
    'company' => [Company::class, CompanyResource::class, ListCompanies::class, ViewCompany::class],
    'person' => [People::class, PeopleResource::class, ListPeople::class, ViewPeople::class],
]);

it('puts the merged notice in the browser tab title', function (string $model, string $resource): void {
    $merged = mergedAwayRecord($model, $this->user, 'Merged Away');

    $this->get($resource::getUrl('view', ['record' => $merged]))
        ->assertSeeInOrder(['<title>', 'This record was merged'], false);
})->with('merge-capable entities');

it('follows a chain of merged records one notice at a time', function (string $model, string $resource): void {
    $second = mergedAwayRecord($model, $this->user, 'Second Hop');
    $first = $model::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'First Hop']);
    $first->delete();
    $model::withTrashed()->whereKey($first->getKey())->update(['merged_into_id' => $second->getKey()]);

    $this->get($resource::getUrl('view', ['record' => $first]))
        ->assertSee($resource::getUrl('view', ['record' => $second]), false)
        ->assertDontSee('First Hop');

    $this->get($resource::getUrl('view', ['record' => $second]))
        ->assertSee('This record was merged')
        ->assertSee($resource::getUrl('view', ['record' => $second->merged_into_id]), false);
})->with('merge-capable entities');

it('lands on the deleted notice when the merge result was deleted', function (string $model, string $resource): void {
    $merged = mergedAwayRecord($model, $this->user, 'Merged Away');
    $model::query()->findOrFail($merged->merged_into_id)->delete();

    $this->get($resource::getUrl('view', ['record' => $merged->merged_into_id]))
        ->assertOk()
        ->assertSee('This record was deleted')
        ->assertDontSee('Merged Away Result');
})->with('merge-capable entities');

it('lands on a 404 when the merge result was force deleted', function (string $model, string $resource): void {
    $merged = mergedAwayRecord($model, $this->user, 'Merged Away');
    $resultId = $merged->merged_into_id;
    $model::query()->findOrFail($resultId)->forceDelete();

    $this->get($resource::getUrl('view', ['record' => $resultId]))->assertNotFound();
})->with('merge-capable entities');

it('shows the deleted notice with a way back to the list on a deleted record page', function (string $model, string $resource, string $list, string $page): void {
    $deleted = deletedOnlyRecord($model, $this->user, 'Plainly Deleted');

    $this->get($resource::getUrl('view', ['record' => $deleted]))
        ->assertOk()
        ->assertSee('This record was deleted')
        ->assertSee('It was deleted and no longer exists here.')
        ->assertDontSee('Plainly Deleted')
        ->assertDontSee('How merging works')
        ->assertSee($resource::getUrl('index'), false)
        ->assertSeeInOrder(['<title>', 'This record was deleted'], false);

    livewire($page, ['record' => $deleted->getKey()])
        ->assertSee('Go back')
        ->assertDontSee(['Plainly Deleted', 'Overview', 'Edit', 'New note', 'New task']);
})->with('deletable entities');

it('leaves a live record page unchanged', function (): void {
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Live Deal']);

    livewire(ViewOpportunity::class, ['record' => $opportunity->getKey()])
        ->assertSee('Live Deal')
        ->assertSee('Overview')
        ->assertDontSee(['This record was deleted', 'This record was merged']);
});

it('finds no deleted record in global search', function (string $model, string $resource): void {
    deletedOnlyRecord($model, $this->user, 'Plainly Deleted');
    $model::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Plainly Alive']);

    expect($resource::getGlobalSearchResults('Plainly Deleted'))->toHaveCount(0)
        ->and($resource::getGlobalSearchResults('Plainly Alive'))->toHaveCount(1);
})->with([
    'company' => [Company::class, CompanyResource::class],
    'person' => [People::class, PeopleResource::class],
    'opportunity' => [Opportunity::class, OpportunityResource::class],
]);

it('gives a trashed row no link and no preview and a live row both', function (string $model, string $resource, string $list): void {
    $deleted = deletedOnlyRecord($model, $this->user, 'Plainly Deleted');
    $live = $model::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Plainly Alive']);

    $table = livewire($list)
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$deleted, $live])
        ->assertTableActionHidden('preview', $deleted)
        ->assertTableActionVisible('preview', $live)
        ->instance()
        ->getTable();

    expect($table->getRecordUrl($deleted))->toBeNull()
        ->and($table->getRecordUrl($live))->toBe($resource::getUrl('view', ['record' => $live]));
})->with([
    'company' => [Company::class, CompanyResource::class, ListCompanies::class],
    'person' => [People::class, PeopleResource::class, ListPeople::class],
    'opportunity' => [Opportunity::class, OpportunityResource::class, ListOpportunities::class],
]);
