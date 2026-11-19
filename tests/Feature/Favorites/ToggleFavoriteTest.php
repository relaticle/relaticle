<?php

declare(strict_types=1);

use App\Actions\Favorites\AddFavoriteRecord;
use App\Actions\Favorites\MoveFavoriteRecord;
use App\Filament\Actions\FavoriteMenuActions;
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
use App\Models\Workspace;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(AddFavoriteRecord::class, ToggleFavoriteAction::class, FavoriteMenuActions::class, MoveFavoriteRecord::class, FavoriteRecord::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
    $this->star = TestAction::make('toggleFavorite')->schemaComponent('recordActions', schema: 'infolist');
    $this->menu = fn (string $name): TestAction => TestAction::make($name)->schemaComponent('recordActions', schema: 'infolist');
});

function favoriteFolderFor(User $user, Workspace $workspace, string $name, int $position): FavoriteFolder
{
    return FavoriteFolder::factory()->create(['workspace_id' => $workspace->getKey(), 'user_id' => $user->getKey(), 'name' => $name, 'position' => $position]);
}

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

it('adds and removes a favorite from the record menu', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    $page = livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertActionVisible(($this->menu)('addFavorite'))
        ->assertActionHidden(($this->menu)('removeFavorite'))
        ->callAction(($this->menu)('addFavorite'))
        ->assertDispatched('refresh-sidebar')
        ->assertActionHidden(($this->menu)('addFavorite'))
        ->assertActionVisible(($this->menu)('removeFavorite'));

    expect(FavoriteRecord::ownedFor($this->user, $company))->not->toBeNull();

    $page->callAction(($this->menu)('removeFavorite'));

    expect(FavoriteRecord::ownedFor($this->user, $company))->toBeNull();
});

it('offers moving a favorite once a folder exists, without a reload', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    favoriteFor($this->user, $this->workspace, $company, 1);

    $page = livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertActionHidden(($this->menu)('moveFavoriteToTop'));

    $folder = favoriteFolderFor($this->user, $this->workspace, 'Q4 renewals', 2);

    $page->dispatch('favorites-updated')
        ->assertActionVisible(($this->menu)('moveFavoriteToTop'))
        ->assertActionVisible(($this->menu)('moveFavoriteTo'.$folder->getKey()));
});

it('hides moving a record that is not a favorite', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    favoriteFolderFor($this->user, $this->workspace, 'Q4 renewals', 1);

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertActionHidden(($this->menu)('moveFavoriteToTop'));
});

it('moves a favorite to the end of a folder and back to the end of the top level', function (): void {
    $folder = favoriteFolderFor($this->user, $this->workspace, 'Q4 renewals', 1);
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    favoriteFor($this->user, $this->workspace, Company::factory()->recycle([$this->user, $this->workspace])->create(), 1, $folder);
    favoriteFor($this->user, $this->workspace, $company, 2);
    favoriteFor($this->user, $this->workspace, Company::factory()->recycle([$this->user, $this->workspace])->create(), 3);

    $page = livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->callAction(($this->menu)('moveFavoriteTo'.$folder->getKey()))
        ->assertDispatched('refresh-sidebar');

    $favorite = FavoriteRecord::ownedFor($this->user, $company);
    expect($favorite->favorite_folder_id)->toBe($folder->getKey())->and($favorite->position)->toBe(2);

    $page->callAction(($this->menu)('moveFavoriteToTop'));

    $favorite->refresh();
    expect($favorite->favorite_folder_id)->toBeNull()->and($favorite->position)->toBe(4);
});

it("refuses moving a favorite into another member's folder", function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    favoriteFor($this->user, $this->workspace, $company, 1);
    $foreign = favoriteFolderFor(User::factory()->withWorkspace()->create(), $this->workspace, 'Theirs', 1);

    expect(fn (): mixed => resolve(MoveFavoriteRecord::class)->execute($this->user, $company, $foreign))
        ->toThrow(HttpException::class);

    expect(FavoriteRecord::ownedFor($this->user, $company)->favorite_folder_id)->toBeNull();
});

it('adds a favorite straight into a chosen folder once a folder exists', function (): void {
    $folder = favoriteFolderFor($this->user, $this->workspace, 'Q4 renewals', 1);
    favoriteFor($this->user, $this->workspace, Company::factory()->recycle([$this->user, $this->workspace])->create(), 1, $folder);
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertActionHidden(($this->menu)('addFavorite'))
        ->assertActionVisible(($this->menu)('addFavoriteToTop'))
        ->assertActionHidden(($this->menu)('moveFavoriteToTop'))
        ->callAction(($this->menu)('addFavoriteTo'.$folder->getKey()))
        ->assertDispatched('refresh-sidebar')
        ->assertActionHidden(($this->menu)('addFavoriteToTop'))
        ->assertActionVisible(($this->menu)('moveFavoriteToTop'));

    $favorite = FavoriteRecord::ownedFor($this->user, $company);
    expect($favorite->favorite_folder_id)->toBe($folder->getKey())->and($favorite->position)->toBe(2);
});

it('adds a favorite to the top level from the folder picker', function (): void {
    $folder = favoriteFolderFor($this->user, $this->workspace, 'Q4 renewals', 1);
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->callAction(($this->menu)('addFavoriteToTop'));

    $favorite = FavoriteRecord::ownedFor($this->user, $company);
    expect($favorite->favorite_folder_id)->toBeNull()->and($favorite->position)->toBe(2);
});

it("refuses adding a favorite into another member's folder", function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $foreign = favoriteFolderFor(User::factory()->withWorkspace()->create(), $this->workspace, 'Theirs', 1);

    expect(fn (): mixed => resolve(AddFavoriteRecord::class)->execute($this->user, $company, $foreign))
        ->toThrow(HttpException::class);

    expect(FavoriteRecord::ownedFor($this->user, $company))->toBeNull();
});

it('stars straight to the top level and un-stars from a folder, without a folder picker', function (): void {
    $folder = favoriteFolderFor($this->user, $this->workspace, 'Q4 renewals', 1);
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    $page = livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->callAction($this->star)
        ->assertActionHasLabel($this->star, __('favorites.actions.remove'));

    expect(FavoriteRecord::ownedFor($this->user, $company)->favorite_folder_id)->toBeNull();

    $page->callAction(($this->menu)('moveFavoriteTo'.$folder->getKey()))
        ->callAction($this->star)
        ->assertActionHasLabel($this->star, __('favorites.actions.add'));

    expect(FavoriteRecord::ownedFor($this->user, $company))->toBeNull();
});
