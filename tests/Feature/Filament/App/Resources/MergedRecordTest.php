<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Models\Company;
use App\Models\People;
use App\Models\User;
use App\Policies\CompanyPolicy;
use App\Policies\PeoplePolicy;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

mutates(CompanyResource::class, PeopleResource::class, CompanyPolicy::class, PeoplePolicy::class, Company::class, People::class);

dataset('merge-capable entities', [
    'company' => [Company::class, CompanyResource::class, ListCompanies::class],
    'person' => [People::class, PeopleResource::class, ListPeople::class],
]);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
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
