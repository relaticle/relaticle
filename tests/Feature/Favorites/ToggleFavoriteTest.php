<?php

declare(strict_types=1);

use App\Actions\Favorites\AddFavoriteRecord;
use App\Actions\Favorites\MoveFavorite;
use App\Actions\Favorites\RemoveFavoriteRecord;
use App\Filament\Actions\FavoriteMenuActions;
use App\Filament\Actions\ToggleFavoriteAction;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Models\Company;
use App\Models\Favorite;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(AddFavoriteRecord::class, RemoveFavoriteRecord::class, ToggleFavoriteAction::class, FavoriteMenuActions::class, MoveFavorite::class, Favorite::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
    $this->star = TestAction::make('toggleFavorite')->schemaComponent('recordActions', schema: 'infolist');
    $this->menu = fn (string $name): TestAction => TestAction::make($name)->schemaComponent('recordActions', schema: 'infolist');
});

dataset('favoritable pages', [
    'company' => [ViewCompany::class, fn (User $user, Workspace $workspace): Company => Company::factory()->recycle([$user, $workspace])->create()],
    'person' => [ViewPeople::class, fn (User $user, Workspace $workspace): People => People::factory()->recycle([$user, $workspace])->create()],
    'opportunity' => [ViewOpportunity::class, fn (User $user, Workspace $workspace): Opportunity => Opportunity::factory()->recycle([$user, $workspace])->create()],
]);

it('stars a :dataset from its record page', function (string $page, Closure $make): void {
    $record = $make($this->user, $this->workspace);

    livewire($page, ['record' => $record->getKey()])
        ->callAction($this->star)
        ->assertDispatched('refresh-sidebar');

    $favorite = Favorite::query()->ownedBy($this->user, $this->workspace->getKey())->sole();

    expect($favorite->record_type)->toBe($record->getMorphClass())
        ->and($favorite->record_id)->toBe($record->getKey())
        ->and($favorite->parent_id)->toBeNull();
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

    expect(Favorite::query()->ownedBy($this->user, $this->workspace->getKey())->count())->toBe(0);
});

it('does not duplicate a favorite starred twice', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    resolve(AddFavoriteRecord::class)->execute($this->user, $company);
    resolve(AddFavoriteRecord::class)->execute($this->user, $company);

    expect(Favorite::query()->ownedBy($this->user, $this->workspace->getKey())->count())->toBe(1);
});

it('appends a new favorite after existing items', function (): void {
    $folder = favoriteFolderFor($this->user, $this->workspace, 'Q4 renewals', 1);
    $existing = favoriteFor($this->user, $this->workspace, Company::factory()->recycle([$this->user, $this->workspace])->create(), 2);
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    $favorite = resolve(AddFavoriteRecord::class)->execute($this->user, $company);

    expect(favoriteOrder($this->user, $this->workspace))->toBe([$folder->getKey(), $existing->getKey(), $favorite->getKey()]);
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

    expect(Favorite::ownedFor($this->user, $company))->not->toBeNull();

    $page->callAction(($this->menu)('removeFavorite'));

    expect(Favorite::ownedFor($this->user, $company))->toBeNull();
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
    $inFolder = favoriteFor($this->user, $this->workspace, Company::factory()->recycle([$this->user, $this->workspace])->create(), 1, $folder);
    $favorite = favoriteFor($this->user, $this->workspace, $company, 2);
    $last = favoriteFor($this->user, $this->workspace, Company::factory()->recycle([$this->user, $this->workspace])->create(), 3);

    $page = livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->callAction(($this->menu)('moveFavoriteTo'.$folder->getKey()))
        ->assertDispatched('refresh-sidebar');

    expect(favoriteOrder($this->user, $this->workspace, $folder))->toBe([$inFolder->getKey(), $favorite->getKey()]);

    $page->callAction(($this->menu)('moveFavoriteToTop'));

    expect(favoriteOrder($this->user, $this->workspace))->toBe([$folder->getKey(), $last->getKey(), $favorite->getKey()]);
});

it("refuses moving a favorite into another member's folder", function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $favorite = favoriteFor($this->user, $this->workspace, $company, 1);
    $foreign = favoriteFolderFor(User::factory()->withWorkspace()->create(), $this->workspace, 'Theirs', 1);

    expect(fn (): mixed => resolve(MoveFavorite::class)->execute($this->user, $favorite, $foreign))
        ->toThrow(HttpException::class);

    expect($favorite->refresh()->parent_id)->toBeNull();
});

it('adds a favorite straight into a chosen folder once a folder exists', function (): void {
    $folder = favoriteFolderFor($this->user, $this->workspace, 'Q4 renewals', 1);
    $inFolder = favoriteFor($this->user, $this->workspace, Company::factory()->recycle([$this->user, $this->workspace])->create(), 1, $folder);
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertActionHidden(($this->menu)('addFavorite'))
        ->assertActionVisible(($this->menu)('addFavoriteToTop'))
        ->assertActionHidden(($this->menu)('moveFavoriteToTop'))
        ->callAction(($this->menu)('addFavoriteTo'.$folder->getKey()))
        ->assertDispatched('refresh-sidebar')
        ->assertActionHidden(($this->menu)('addFavoriteToTop'))
        ->assertActionVisible(($this->menu)('moveFavoriteToTop'));

    expect(favoriteOrder($this->user, $this->workspace, $folder))->toBe([$inFolder->getKey(), Favorite::ownedFor($this->user, $company)->getKey()]);
});

it('adds a favorite to the top level from the folder picker', function (): void {
    $folder = favoriteFolderFor($this->user, $this->workspace, 'Q4 renewals', 1);
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->callAction(($this->menu)('addFavoriteToTop'));

    expect(favoriteOrder($this->user, $this->workspace))->toBe([$folder->getKey(), Favorite::ownedFor($this->user, $company)->getKey()]);
});

it("refuses adding a favorite into another member's folder", function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $foreign = favoriteFolderFor(User::factory()->withWorkspace()->create(), $this->workspace, 'Theirs', 1);

    expect(fn (): mixed => resolve(AddFavoriteRecord::class)->execute($this->user, $company, $foreign))
        ->toThrow(HttpException::class);

    expect(Favorite::ownedFor($this->user, $company))->toBeNull();
});

it('stars straight to the top level and un-stars from a folder, without a folder picker', function (): void {
    $folder = favoriteFolderFor($this->user, $this->workspace, 'Q4 renewals', 1);
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    $page = livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->callAction($this->star)
        ->assertActionHasLabel($this->star, __('favorites.actions.remove'));

    expect(Favorite::ownedFor($this->user, $company)->parent_id)->toBeNull();

    $page->callAction(($this->menu)('moveFavoriteTo'.$folder->getKey()))
        ->callAction($this->star)
        ->assertActionHasLabel($this->star, __('favorites.actions.add'));

    expect(Favorite::ownedFor($this->user, $company))->toBeNull();
});

it('reads the favorites table a fixed number of times per record page render', function (): void {
    foreach (range(1, 5) as $position) {
        favoriteFolderFor($this->user, $this->workspace, "Folder {$position}", $position);
    }
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    favoriteFor($this->user, $this->workspace, $company, 6);

    DB::enableQueryLog();
    livewire(ViewCompany::class, ['record' => $company->getKey()]);

    expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], '"favorites"'))->count())->toBe(2);
});

it("refuses starring another workspace's record", function (): void {
    $outsider = User::factory()->withWorkspace()->create();
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    expect(fn (): mixed => resolve(AddFavoriteRecord::class)->execute($outsider, $company))
        ->toThrow(HttpException::class);

    expect(Favorite::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuses un-starring for a member who left the workspace', function (): void {
    $member = User::factory()->create(['current_workspace_id' => $this->workspace->getKey()]);
    $this->workspace->users()->attach($member, ['role' => 'member']);
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    favoriteFor($member, $this->workspace, $company, 1);
    $this->workspace->users()->detach($member);
    $member->unsetRelation('workspaces');

    expect(fn (): mixed => resolve(RemoveFavoriteRecord::class)->execute($member, $company))
        ->toThrow(HttpException::class);

    expect(Favorite::query()->withoutGlobalScopes()->whereBelongsTo($member)->count())->toBe(1);
});
