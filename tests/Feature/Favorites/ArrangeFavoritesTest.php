<?php

declare(strict_types=1);

use App\Actions\Favorites\ArrangeFavorites;
use App\Livewire\App\FavoritesSidebarNav;
use App\Models\Company;
use App\Models\FavoriteFolder;
use App\Models\FavoriteRecord;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

mutates(ArrangeFavorites::class, FavoritesSidebarNav::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
});

function arrangedFavorite(int $position, ?FavoriteFolder $folder = null, ?User $user = null): FavoriteRecord
{
    $company = Company::factory()->recycle([test()->user, test()->workspace])->create();

    return favoriteFor($user ?? test()->user, test()->workspace, $company, $position, $folder);
}

function arrangedFolder(int $position, ?User $user = null): FavoriteFolder
{
    return FavoriteFolder::factory()->create([
        'workspace_id' => test()->workspace->getKey(),
        'user_id' => ($user ?? test()->user)->getKey(),
        'position' => $position,
    ]);
}

/**
 * @return list<array{0: string, 1: string|null, 2: int}>
 */
function placements(FavoriteRecord|FavoriteFolder ...$items): array
{
    return array_map(fn (FavoriteRecord|FavoriteFolder $item): array => [
        $item->getKey(),
        $item instanceof FavoriteRecord ? $item->refresh()->favorite_folder_id : null,
        $item->refresh()->position,
    ], $items);
}

it('reorders the top level', function (): void {
    $folder = arrangedFolder(1);
    $favorite = arrangedFavorite(2);

    Livewire::test(FavoritesSidebarNav::class)
        ->call('arrange', ['top' => ['record:'.$favorite->getKey(), 'folder:'.$folder->getKey()], 'folders' => []])
        ->assertDispatched('refresh-sidebar');

    expect(placements($favorite, $folder))->toBe([
        [$favorite->getKey(), null, 1],
        [$folder->getKey(), null, 2],
    ]);
});

it('moves a favorite into, out of and between folders', function (): void {
    $first = arrangedFolder(1);
    $second = arrangedFolder(2);
    $loose = arrangedFavorite(3);
    $inside = arrangedFavorite(1, $first);
    $folders = 'folder:'.$first->getKey();
    $secondKey = 'folder:'.$second->getKey();

    Livewire::test(FavoritesSidebarNav::class)
        ->call('arrange', ['top' => [$folders, $secondKey], 'folders' => [$first->getKey() => [$inside->getKey(), $loose->getKey()]]])
        ->assertDispatched('refresh-sidebar');

    expect(placements($inside, $loose))->toBe([
        [$inside->getKey(), $first->getKey(), 1],
        [$loose->getKey(), $first->getKey(), 2],
    ]);

    Livewire::test(FavoritesSidebarNav::class)
        ->call('arrange', ['top' => [$folders, 'record:'.$inside->getKey(), $secondKey], 'folders' => [$first->getKey() => [$loose->getKey()]]])
        ->assertDispatched('refresh-sidebar');

    expect(placements($first, $inside, $second, $loose))->toBe([
        [$first->getKey(), null, 1],
        [$inside->getKey(), null, 2],
        [$second->getKey(), null, 3],
        [$loose->getKey(), $first->getKey(), 1],
    ]);

    Livewire::test(FavoritesSidebarNav::class)
        ->call('arrange', ['top' => [$folders, 'record:'.$inside->getKey(), $secondKey], 'folders' => [$first->getKey() => [], $second->getKey() => [$loose->getKey()]]])
        ->assertDispatched('refresh-sidebar');

    expect(placements($loose, $inside))->toBe([
        [$loose->getKey(), $second->getKey(), 1],
        [$inside->getKey(), null, 2],
    ]);
});

it('keeps a hidden favorite in its folder and place through a drag', function (): void {
    $folder = arrangedFolder(1);
    $x = arrangedFavorite(1, $folder);
    $hidden = arrangedFavorite(2, $folder);
    $y = arrangedFavorite(3, $folder);
    $hidden->record->delete();

    Livewire::test(FavoritesSidebarNav::class)
        ->call('arrange', ['top' => ['folder:'.$folder->getKey()], 'folders' => [$folder->getKey() => [$y->getKey(), $x->getKey()]]])
        ->assertDispatched('refresh-sidebar');

    expect(placements($y, $hidden, $x))->toBe([
        [$y->getKey(), $folder->getKey(), 1],
        [$hidden->getKey(), $folder->getKey(), 2],
        [$x->getKey(), $folder->getKey(), 3],
    ]);
});

it('keeps a favorite added elsewhere when a stale tree is posted', function (): void {
    $folder = arrangedFolder(1);
    $a = arrangedFavorite(1, $folder);
    $added = arrangedFavorite(2, $folder);
    $b = arrangedFavorite(3, $folder);
    $deleted = arrangedFavorite(2);
    $deletedKey = $deleted->getKey();
    $deleted->delete();

    Livewire::test(FavoritesSidebarNav::class)
        ->call('arrange', ['top' => ['record:'.$deletedKey, 'folder:'.$folder->getKey()], 'folders' => [$folder->getKey() => [$b->getKey(), $deletedKey, $a->getKey()]]])
        ->assertDispatched('refresh-sidebar');

    expect(placements($b, $added, $a, $folder))->toBe([
        [$b->getKey(), $folder->getKey(), 1],
        [$added->getKey(), $folder->getKey(), 2],
        [$a->getKey(), $folder->getKey(), 3],
        [$folder->getKey(), null, 1],
    ]);
});

it("leaves another member's favorites untouched", function (): void {
    $other = User::factory()->withWorkspace()->create();
    $theirFolder = arrangedFolder(1, $other);
    $theirFavorite = arrangedFavorite(2, null, $other);
    $theirFiled = arrangedFavorite(1, $theirFolder, $other);
    $mine = arrangedFavorite(1);

    Livewire::test(FavoritesSidebarNav::class)
        ->call('arrange', [
            'top' => ['record:'.$theirFavorite->getKey(), 'folder:'.$theirFolder->getKey(), 'record:'.$theirFiled->getKey()],
            'folders' => [$theirFolder->getKey() => [$mine->getKey(), $theirFavorite->getKey()]],
        ])
        ->assertDispatched('refresh-sidebar');

    expect(placements($theirFolder, $theirFavorite, $theirFiled, $mine))->toBe([
        [$theirFolder->getKey(), null, 1],
        [$theirFavorite->getKey(), null, 2],
        [$theirFiled->getKey(), $theirFolder->getKey(), 1],
        [$mine->getKey(), null, 1],
    ]);
});

it('never puts a folder inside a folder', function (): void {
    $outer = arrangedFolder(1);
    $inner = arrangedFolder(2);

    Livewire::test(FavoritesSidebarNav::class)
        ->call('arrange', ['top' => ['folder:'.$outer->getKey()], 'folders' => [$outer->getKey() => ['folder:'.$inner->getKey(), $inner->getKey()]]])
        ->assertDispatched('refresh-sidebar');

    expect(placements($outer, $inner))->toBe([
        [$outer->getKey(), null, 1],
        [$inner->getKey(), null, 2],
    ]);
});

it('ignores a malformed tree', function (mixed $tree): void {
    $favorite = arrangedFavorite(1);

    Livewire::test(FavoritesSidebarNav::class)
        ->call('arrange', $tree)
        ->assertDispatched('refresh-sidebar');

    expect(placements($favorite))->toBe([[$favorite->getKey(), null, 1]]);
})->with([
    'empty' => [[]],
    'scalars' => [['top' => 'record:x', 'folders' => 'nope']],
    'nested junk' => [['top' => [['record:x'], 7, null, 'mystery:1'], 'folders' => [5 => ['a'], 'x' => 'b']]],
]);

it('refuses a member outside the workspace', function (): void {
    $this->actingAs(User::factory()->withWorkspace()->create());

    Livewire::test(FavoritesSidebarNav::class)
        ->call('arrange', ['top' => [], 'folders' => []])
        ->assertForbidden();
});
