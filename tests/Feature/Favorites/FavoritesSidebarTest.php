<?php

declare(strict_types=1);

use App\Enums\CrmEntity;
use App\Filament\Pages\Dashboard;
use App\Livewire\App\FavoritesSidebarNav;
use App\Models\Company;
use App\Models\FavoriteFolder;
use App\Models\FavoriteRecord;
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
        ->assertSee($urls->buildOrFail(CrmEntity::Company, $company->getKey(), $this->workspace))
        ->assertSee($urls->buildOrFail(CrmEntity::People, $person->getKey(), $this->workspace))
        ->assertDontSee(__('favorites.empty'));
});

it('shows the empty hint when nothing is starred', function (): void {
    Livewire::test(FavoritesSidebarNav::class)
        ->assertSee(__('favorites.empty'))
        ->assertSee(__('favorites.actions.new_folder'));
});

it('lists a folder with its favorites between top-level favorites', function (): void {
    $folder = FavoriteFolder::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->user->getKey(),
        'name' => 'Q4 renewals',
        'position' => 2,
    ]);
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
        ->assertSee(__('favorites.empty'));
});

it('removes a favorite from the sidebar', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);
    $favorite = favoriteFor($this->user, $this->workspace, $company, 1);

    Livewire::test(FavoritesSidebarNav::class)
        ->assertSeeHtml("removeFavorite('{$favorite->getKey()}')")
        ->call('removeFavorite', $favorite->getKey())
        ->assertDispatched('refresh-sidebar');

    expect(FavoriteRecord::query()->whereKey($favorite->getKey())->exists())->toBeFalse();
});

it('removes a favorite whose record was trashed meanwhile', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $favorite = favoriteFor($this->user, $this->workspace, $company, 1);
    $company->delete();

    Livewire::test(FavoritesSidebarNav::class)
        ->call('removeFavorite', $favorite->getKey())
        ->assertDispatched('refresh-sidebar');

    expect(FavoriteRecord::query()->whereKey($favorite->getKey())->exists())->toBeFalse();
});

it("ignores removing another member's favorite", function (): void {
    $other = User::factory()->withWorkspace()->create();
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $favorite = favoriteFor($other, $this->workspace, $company, 1);

    Livewire::test(FavoritesSidebarNav::class)
        ->call('removeFavorite', $favorite->getKey())
        ->assertNotDispatched('refresh-sidebar');

    expect(FavoriteRecord::query()->withoutGlobalScopes()->whereKey($favorite->getKey())->exists())->toBeTrue();
});

it('renders the favorites group in the app sidebar above the chats', function (): void {
    $html = $this->get(Dashboard::getUrl(tenant: $this->workspace))->assertOk()->getContent();

    expect(strpos($html, 'fi-sidebar-favorites'))->not->toBeFalse()
        ->toBeLessThan(strpos($html, 'fi-sidebar-chats'));
});
