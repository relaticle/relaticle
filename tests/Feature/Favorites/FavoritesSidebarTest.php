<?php

declare(strict_types=1);

use App\Enums\CrmEntity;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Livewire\App\FavoritesSidebarNav;
use App\Models\Company;
use App\Models\Favorite;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Favorites\FavoritesQuery;
use App\Support\CanonicalRecordUrl;
use Filament\Facades\Filament;
use Livewire\Livewire;

mutates(FavoritesSidebarNav::class, FavoritesQuery::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
});

it('lists favorites with their name and entity label', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Dana Whitfield']);
    favoriteFor($this->user, $this->workspace, $company, 1);
    favoriteFor($this->user, $this->workspace, $person, 2);
    $urls = new CanonicalRecordUrl;

    Livewire::test(FavoritesSidebarNav::class)
        ->assertSee(__('favorites.group'))
        ->assertSeeInOrder(['Acme Corp', __('favorites.entities.company'), 'Dana Whitfield', __('favorites.entities.people')])
        ->assertSeeHtml('href="'.e($urls->buildOrFail(CrmEntity::Company, $company->getKey(), $this->workspace)).'"')
        ->assertSeeHtml('href="'.e($urls->buildOrFail(CrmEntity::People, $person->getKey(), $this->workspace)).'"');
});

it('hides the group while there is no favorite and no folder', function (): void {
    Livewire::test(FavoritesSidebarNav::class)
        ->assertDontSeeHtml('fi-sidebar-group-btn')
        ->assertDontSee(__('favorites.actions.new_folder'));
});

it('shows the group with only an empty folder', function (): void {
    favoriteFolderFor($this->user, $this->workspace, 'Q4 renewals', 1);

    Livewire::test(FavoritesSidebarNav::class)
        ->assertSeeHtml('fi-sidebar-group-btn')
        ->assertSee(__('favorites.actions.new_folder'))
        ->assertSee('Q4 renewals')
        ->assertSee(__('favorites.folders.empty'));
});

it('shows the group when the only favorite sits in a folder', function (): void {
    $folder = favoriteFolderFor($this->user, $this->workspace, 'Q4 renewals', 1);
    favoriteFor($this->user, $this->workspace, Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']), 1, $folder);

    Livewire::test(FavoritesSidebarNav::class)
        ->assertSee(__('favorites.actions.new_folder'))
        ->assertSeeInOrder(['Q4 renewals', 'Acme Corp']);
});

it('lists a folder with its favorites between top-level favorites', function (): void {
    $folder = favoriteFolderFor($this->user, $this->workspace, 'Q4 renewals', 2);
    $companies = Company::factory()->recycle([$this->user, $this->workspace])
        ->forEachSequence(['name' => 'Acme Corp'], ['name' => 'Globex expansion'], ['name' => 'Initech renewal'])
        ->create();
    favoriteFor($this->user, $this->workspace, $companies[0], 1);
    favoriteFor($this->user, $this->workspace, $companies[2], 1, $folder);
    favoriteFor($this->user, $this->workspace, $companies[1], 3);

    Livewire::test(FavoritesSidebarNav::class)
        ->assertSeeInOrder(['Acme Corp', 'Q4 renewals', 'Initech renewal', 'Globex expansion'])
        ->assertSeeHtml("toggleCollapsedGroup('favorites-folder:{$folder->getKey()}')");
});

it('hides a trashed record and shows it again in its old place once restored', function (): void {
    $companies = Company::factory()->recycle([$this->user, $this->workspace])
        ->forEachSequence(['name' => 'Acme Corp'], ['name' => 'Globex Inc'], ['name' => 'Initech Ltd'])
        ->create();

    foreach ($companies as $index => $company) {
        favoriteFor($this->user, $this->workspace, $company, $index + 1);
    }

    $companies[1]->delete();

    Livewire::test(FavoritesSidebarNav::class)
        ->assertSeeInOrder(['Acme Corp', 'Initech Ltd'])
        ->assertDontSee('Globex Inc');

    $companies[1]->restore();

    Livewire::test(FavoritesSidebarNav::class)
        ->assertSeeInOrder(['Acme Corp', 'Globex Inc', 'Initech Ltd']);
});

it("shows only the signed-in member's favorites in the current workspace", function (): void {
    $other = User::factory()->withWorkspace()->create();
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);
    favoriteFor($other, $this->workspace, $company, 1);

    $secondWorkspace = Workspace::factory()->create();
    $foreign = Company::factory()->create(['workspace_id' => $secondWorkspace->getKey(), 'name' => 'Globex Inc']);
    favoriteFor($this->user, $secondWorkspace, $foreign, 1);

    Livewire::test(FavoritesSidebarNav::class)
        ->assertDontSee('Acme Corp')
        ->assertDontSee('Globex Inc')
        ->assertDontSeeHtml('fi-sidebar-group-btn');
});

it('removes a favorite from the sidebar', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);
    $favorite = favoriteFor($this->user, $this->workspace, $company, 1);

    Livewire::test(FavoritesSidebarNav::class)
        ->assertSeeHtml("removeFavorite('{$favorite->getKey()}')")
        ->call('removeFavorite', $favorite->getKey())
        ->assertDispatched('refresh-sidebar');

    expect(Favorite::query()->whereKey($favorite->getKey())->exists())->toBeFalse();
});

it('removes a favorite whose record was trashed meanwhile', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $favorite = favoriteFor($this->user, $this->workspace, $company, 1);
    $company->delete();

    Livewire::test(FavoritesSidebarNav::class)
        ->call('removeFavorite', $favorite->getKey())
        ->assertDispatched('refresh-sidebar');

    expect(Favorite::query()->whereKey($favorite->getKey())->exists())->toBeFalse();
});

it("ignores removing another member's favorite", function (): void {
    $other = User::factory()->withWorkspace()->create();
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $favorite = favoriteFor($other, $this->workspace, $company, 1);

    Livewire::test(FavoritesSidebarNav::class)
        ->call('removeFavorite', $favorite->getKey())
        ->assertNotDispatched('refresh-sidebar');

    expect(Favorite::query()->withoutGlobalScopes()->whereKey($favorite->getKey())->exists())->toBeTrue();
});

it('renders the favorites group in the app sidebar above the chats', function (): void {
    $html = $this->get(Dashboard::getUrl(tenant: $this->workspace))->assertOk()->getContent();

    expect(strpos($html, 'fi-sidebar-favorites'))->not->toBeFalse()
        ->toBeLessThan(strpos($html, 'fi-sidebar-chats'));
});

it('hides a favorite once the member may no longer view its record', function (): void {
    $member = User::factory()->create(['current_workspace_id' => $this->workspace->getKey()]);
    $this->workspace->users()->attach($member, ['role' => 'member']);
    $this->actingAs($member);
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);
    favoriteFor($member, $this->workspace, $company, 1);

    Livewire::test(FavoritesSidebarNav::class)->assertSee('Acme Corp');

    $this->workspace->users()->detach($member);
    $member->unsetRelation('workspaces');

    Livewire::test(FavoritesSidebarNav::class)->assertDontSee('Acme Corp');
});

it('forgets a favorite when its record is deleted for good', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $favorite = favoriteFor($this->user, $this->workspace, $company, 1);
    $company->delete();

    livewire(ListCompanies::class)
        ->filterTable('trashed', false)
        ->selectTableRecords([$company])
        ->callAction([['name' => 'forceDelete', 'context' => ['table' => true, 'bulk' => true]]]);

    expect(Company::withTrashed()->whereKey($company->getKey())->exists())->toBeFalse()
        ->and(Favorite::query()->withoutGlobalScopes()->whereKey($favorite->getKey())->exists())->toBeFalse();
});
