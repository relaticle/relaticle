<?php

declare(strict_types=1);

use App\Actions\Favorites\CreateFavoriteFolder;
use App\Actions\Favorites\DeleteFavoriteFolder;
use App\Actions\Favorites\RenameFavoriteFolder;
use App\Livewire\App\FavoritesSidebarNav;
use App\Models\Company;
use App\Models\FavoriteFolder;
use App\Models\FavoriteRecord;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
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

it('creates an empty folder before any favorites exist', function (): void {
    Livewire::test(FavoritesSidebarNav::class)
        ->callAction('createFolder', data: ['name' => '  Q4 renewals  '])
        ->assertHasNoFormErrors()
        ->assertDispatched('refresh-sidebar')
        ->assertSee('Q4 renewals');

    $folder = FavoriteFolder::query()->sole();
    expect($folder->name)->toBe('Q4 renewals')->and($folder->position)->toBe(1);
});

it('refuses a blank or too long folder name', function (string $name): void {
    Livewire::test(FavoritesSidebarNav::class)
        ->callAction('createFolder', data: ['name' => $name])
        ->assertHasFormErrors(['name']);

    expect(FavoriteFolder::query()->count())->toBe(0);
})->with(['blank' => '   ', 'too long' => fn (): string => str_repeat('a', 61)]);

it('renames a folder', function (): void {
    $folder = FavoriteFolder::factory()->create(['workspace_id' => $this->workspace->getKey(), 'user_id' => $this->user->getKey(), 'name' => 'Old', 'position' => 1]);

    Livewire::test(FavoritesSidebarNav::class)
        ->callAction(TestAction::make('renameFolder')->arguments(['folder' => $folder->getKey()]), data: ['name' => 'New'])
        ->assertHasNoFormErrors()
        ->assertDispatched('refresh-sidebar');

    expect($folder->refresh()->name)->toBe('New');
});

it('deletes a folder and moves its favorites to the end of the top level', function (): void {
    $folder = FavoriteFolder::factory()->create(['workspace_id' => $this->workspace->getKey(), 'user_id' => $this->user->getKey(), 'position' => 2]);
    $a = favoriteFor($this->user, $this->workspace, folderCompany('A'), 1);
    $b = favoriteFor($this->user, $this->workspace, folderCompany('B'), 1, $folder);
    $c = favoriteFor($this->user, $this->workspace, folderCompany('C'), 2, $folder);

    Livewire::test(FavoritesSidebarNav::class)
        ->callAction(TestAction::make('deleteFolder')->arguments(['folder' => $folder->getKey()]))
        ->assertDispatched('refresh-sidebar');

    expect(FavoriteFolder::query()->count())->toBe(0);
    expect(FavoriteRecord::query()->orderBy('position')->get()->map(fn (FavoriteRecord $f): array => [$f->getKey(), $f->position, $f->favorite_folder_id])->all())
        ->toBe([[$a->getKey(), 1, null], [$b->getKey(), 2, null], [$c->getKey(), 3, null]]);
});

it('moves a trashed favorite out of a deleted folder too', function (): void {
    $folder = FavoriteFolder::factory()->create(['workspace_id' => $this->workspace->getKey(), 'user_id' => $this->user->getKey(), 'position' => 1]);
    $company = folderCompany('Gone');
    $favorite = favoriteFor($this->user, $this->workspace, $company, 1, $folder);
    $company->delete();

    Livewire::test(FavoritesSidebarNav::class)
        ->callAction(TestAction::make('deleteFolder')->arguments(['folder' => $folder->getKey()]));

    expect($favorite->refresh()->favorite_folder_id)->toBeNull()->and($favorite->position)->toBe(1);

    $company->restore();

    Livewire::test(FavoritesSidebarNav::class)->assertSee('Gone');
});

it('refuses renaming or deleting another member\'s folder', function (): void {
    $other = User::factory()->create();
    $this->workspace->users()->attach($other, ['role' => 'admin']);
    $folder = FavoriteFolder::factory()->create(['workspace_id' => $this->workspace->getKey(), 'user_id' => $other->getKey(), 'name' => 'Theirs', 'position' => 1]);

    expect(fn () => resolve(RenameFavoriteFolder::class)->execute($this->user, $folder, 'Mine'))->toThrow(HttpException::class);
    expect(fn () => resolve(DeleteFavoriteFolder::class)->execute($this->user, $folder))->toThrow(HttpException::class);

    expect($folder->refresh()->name)->toBe('Theirs');
});
