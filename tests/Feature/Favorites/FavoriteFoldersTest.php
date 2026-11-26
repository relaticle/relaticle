<?php

declare(strict_types=1);

use App\Actions\Favorites\CreateFavoriteFolder;
use App\Actions\Favorites\DeleteFavoriteFolder;
use App\Actions\Favorites\RenameFavoriteFolder;
use App\Enums\FavoriteKind;
use App\Livewire\App\FavoritesSidebarNav;
use App\Models\Company;
use App\Models\Favorite;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(CreateFavoriteFolder::class, RenameFavoriteFolder::class, DeleteFavoriteFolder::class, FavoritesSidebarNav::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
});

function folderCompany(string $name): Company
{
    return Company::factory()->recycle([test()->user, test()->workspace])->create(['name' => $name]);
}

it('creates an empty folder after the first favorite', function (): void {
    $first = favoriteFor($this->user, $this->workspace, folderCompany('Acme Corp'), 1);

    Livewire::test(FavoritesSidebarNav::class)
        ->callAction('createFolder', data: ['name' => '  Q4 renewals  '])
        ->assertHasNoFormErrors()
        ->assertDispatched('refresh-sidebar')
        ->assertDispatched('favorites-updated')
        ->assertSee('Q4 renewals');

    $folder = Favorite::query()->folders()->sole();
    expect($folder->name)->toBe('Q4 renewals')
        ->and(favoriteOrder($this->user, $this->workspace))->toBe([$first->getKey(), $folder->getKey()]);
});

it('creates a folder and keeps the modal open, emptied, to create another', function (): void {
    favoriteFor($this->user, $this->workspace, folderCompany('Acme Corp'), 1);

    Livewire::test(FavoritesSidebarNav::class)
        ->mountAction('createFolder')
        ->fillForm(['name' => 'Q4 renewals'])
        ->callMountedAction(['another' => true])
        ->assertHasNoFormErrors()
        ->assertDispatched('favorites-folder-created')
        ->assertNotDispatched('refresh-sidebar')
        ->assertActionMounted('createFolder')
        ->assertActionDataSet(['name' => null])
        ->fillForm(['name' => 'Partners'])
        ->callMountedAction()
        ->assertSet('mountedActions', []);

    expect(Favorite::query()->folders()->orderBy('position')->pluck('name')->all())->toBe(['Q4 renewals', 'Partners']);
});

it('labels the folder modal buttons Create and Create & create another', function (): void {
    favoriteFor($this->user, $this->workspace, folderCompany('Acme Corp'), 1);

    Livewire::test(FavoritesSidebarNav::class)
        ->mountAction('createFolder')
        ->assertMountedActionModalSee([
            __('filament-actions::create.single.modal.actions.create.label'),
            __('filament-actions::create.single.modal.actions.create_another.label'),
        ]);
});

it('refuses a blank or too long folder name', function (string $name): void {
    Livewire::test(FavoritesSidebarNav::class)
        ->callAction('createFolder', data: ['name' => $name])
        ->assertHasFormErrors(['name']);

    expect(fn (): mixed => resolve(CreateFavoriteFolder::class)->execute($this->user, $this->workspace, $name))->toThrow(ValidationException::class);

    expect(Favorite::query()->folders()->count())->toBe(0);
})->with(['blank' => '   ', 'too long' => fn (): string => str_repeat('a', 61)]);

it('renames a folder', function (): void {
    $folder = favoriteFolderFor($this->user, $this->workspace, 'Old', 1);

    Livewire::test(FavoritesSidebarNav::class)
        ->callAction(TestAction::make('renameFolder')->arguments(['folder' => $folder->getKey()]), data: ['name' => 'New'])
        ->assertHasNoFormErrors()
        ->assertDispatched('refresh-sidebar');

    expect($folder->refresh()->name)->toBe('New');
});

it('deletes a folder and moves its favorites to the end of the top level', function (): void {
    $folder = favoriteFolderFor($this->user, $this->workspace, 'Q4 renewals', 2);
    $a = favoriteFor($this->user, $this->workspace, folderCompany('A'), 1);
    $b = favoriteFor($this->user, $this->workspace, folderCompany('B'), 1, $folder);
    $c = favoriteFor($this->user, $this->workspace, folderCompany('C'), 2, $folder);

    Livewire::test(FavoritesSidebarNav::class)
        ->callAction(TestAction::make('deleteFolder')->arguments(['folder' => $folder->getKey()]))
        ->assertDispatched('refresh-sidebar');

    expect(Favorite::query()->folders()->count())->toBe(0)
        ->and(favoriteOrder($this->user, $this->workspace))->toBe([$a->getKey(), $b->getKey(), $c->getKey()])
        ->and(Favorite::query()->whereNull('parent_id')->distinct()->count('position'))->toBe(3);
});

it('moves a trashed favorite out of a deleted folder too', function (): void {
    $folder = favoriteFolderFor($this->user, $this->workspace, 'Q4 renewals', 1);
    $company = folderCompany('Gone');
    $favorite = favoriteFor($this->user, $this->workspace, $company, 1, $folder);
    $company->delete();

    Livewire::test(FavoritesSidebarNav::class)
        ->callAction(TestAction::make('deleteFolder')->arguments(['folder' => $folder->getKey()]));

    expect($favorite->refresh()->parent_id)->toBeNull();

    $company->restore();

    Livewire::test(FavoritesSidebarNav::class)->assertSee('Gone');
});

it('refuses renaming or deleting another member\'s folder', function (): void {
    $other = User::factory()->create();
    $this->workspace->users()->attach($other, ['role' => 'admin']);
    $folder = favoriteFolderFor($other, $this->workspace, 'Theirs', 1);

    expect(fn (): mixed => resolve(RenameFavoriteFolder::class)->execute($this->user, $folder, 'Mine'))->toThrow(HttpException::class);
    expect(fn (): mixed => resolve(DeleteFavoriteFolder::class)->execute($this->user, $folder))->toThrow(HttpException::class);

    expect($folder->refresh()->name)->toBe('Theirs');
});

it('refuses a folder in a workspace the member does not belong to', function (): void {
    $otherWorkspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    Filament::setTenant($otherWorkspace);

    Livewire::test(FavoritesSidebarNav::class)
        ->callAction('createFolder', data: ['name' => 'Theirs'])
        ->assertForbidden();

    expect(Favorite::query()->withoutGlobalScopes()->folders()->exists())->toBeFalse();
});

it('stores every kind of favorite the database shape allows', function (): void {
    foreach (FavoriteKind::cases() as $kind) {
        $favorite = match ($kind) {
            FavoriteKind::Record => favoriteFor($this->user, $this->workspace, folderCompany('Acme Corp'), 1),
            FavoriteKind::Folder => favoriteFolderFor($this->user, $this->workspace, 'Q4 renewals', 2),
        };

        expect($favorite->fresh()->kind)->toBe($kind);
    }
});
