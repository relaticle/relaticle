<?php

declare(strict_types=1);

use App\Actions\Favorites\AddFavoriteRecord;
use App\Filament\Actions\ToggleFavoriteAction;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Models\Company;
use App\Models\FavoriteFolder;
use App\Models\FavoriteRecord;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

mutates(AddFavoriteRecord::class, ToggleFavoriteAction::class, FavoriteRecord::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
    $this->star = TestAction::make('toggleFavorite')->schemaComponent('recordActions', schema: 'infolist');
});

dataset('favoritable pages', [
    'company' => [ViewCompany::class, fn (User $user, $workspace): Company => Company::factory()->recycle([$user, $workspace])->create()],
    'person' => [ViewPeople::class, fn (User $user, $workspace): People => People::factory()->recycle([$user, $workspace])->create()],
    'opportunity' => [ViewOpportunity::class, fn (User $user, $workspace): Opportunity => Opportunity::factory()->recycle([$user, $workspace])->create()],
]);

it('stars a :dataset from its record page', function (string $page, Closure $make): void {
    $record = $make($this->user, $this->workspace);

    livewire($page, ['record' => $record->getKey()])
        ->callAction($this->star)
        ->assertDispatched('refresh-sidebar');

    $favorite = FavoriteRecord::query()->ownedBy($this->user, $this->workspace->getKey())->sole();

    expect($favorite->record_type)->toBe($record->getMorphClass())
        ->and($favorite->record_id)->toBe($record->getKey())
        ->and($favorite->favorite_folder_id)->toBeNull()
        ->and($favorite->position)->toBe(1);
})->with('favoritable pages');

it('labels the action by state', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertActionHasLabel($this->star, __('favorites.actions.add'))
        ->callAction($this->star)
        ->assertActionHasLabel($this->star, __('favorites.actions.remove'));
});

it('un-stars a record', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->callAction($this->star)
        ->callAction($this->star);

    expect(FavoriteRecord::query()->ownedBy($this->user, $this->workspace->getKey())->count())->toBe(0);
});

it('does not duplicate a favorite starred twice', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    resolve(AddFavoriteRecord::class)->execute($this->user, $company);
    resolve(AddFavoriteRecord::class)->execute($this->user, $company);

    expect(FavoriteRecord::query()->ownedBy($this->user, $this->workspace->getKey())->count())->toBe(1);
});

it('appends a new favorite after existing items', function (): void {
    FavoriteFolder::factory()->create(['workspace_id' => $this->workspace->getKey(), 'user_id' => $this->user->getKey(), 'position' => 1]);
    FavoriteRecord::factory()->create(['workspace_id' => $this->workspace->getKey(), 'user_id' => $this->user->getKey(), 'position' => 2]);
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    $favorite = resolve(AddFavoriteRecord::class)->execute($this->user, $company);

    expect($favorite->position)->toBe(3);
});
