<?php

declare(strict_types=1);

use App\Actions\Favorites\MoveFavorite;
use App\Livewire\App\FavoritesSidebarNav;
use App\Models\Company;
use App\Models\Favorite;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

mutates(MoveFavorite::class, FavoritesSidebarNav::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
});

function movedFavorite(int $position, ?Favorite $folder = null): Favorite
{
    return favoriteFor(test()->user, test()->workspace, Company::factory()->recycle([test()->user, test()->workspace])->create(), $position, $folder);
}

it('places a favorite between its new neighbours and writes only that row', function (): void {
    [$a, $b, $c] = [movedFavorite(1), movedFavorite(2), movedFavorite(3)];

    Livewire::test(FavoritesSidebarNav::class)
        ->call('move', $c->getKey(), null, $a->getKey(), $b->getKey())
        ->assertDispatched('refresh-sidebar')
        ->assertDispatched('favorites-updated');

    expect(favoriteOrder($this->user, $this->workspace))->toBe([$a->getKey(), $c->getKey(), $b->getKey()])
        ->and($a->fresh()->updated_at)->toEqual($a->updated_at)
        ->and($b->fresh()->updated_at)->toEqual($b->updated_at);
});

it('moves a favorite into an empty folder, between folders and back out', function (): void {
    $first = favoriteFolderFor($this->user, $this->workspace, 'First', 1);
    $second = favoriteFolderFor($this->user, $this->workspace, 'Second', 2);
    $resident = movedFavorite(1, $second);
    $favorite = movedFavorite(3);
    $component = Livewire::test(FavoritesSidebarNav::class);

    $component->call('move', $favorite->getKey(), $first->getKey(), null, null);
    expect(favoriteOrder($this->user, $this->workspace, $first))->toBe([$favorite->getKey()]);

    $component->call('move', $favorite->getKey(), $second->getKey(), null, $resident->getKey());
    expect(favoriteOrder($this->user, $this->workspace, $first))->toBe([])
        ->and(favoriteOrder($this->user, $this->workspace, $second))->toBe([$favorite->getKey(), $resident->getKey()]);

    $component->call('move', $favorite->getKey(), null, $first->getKey(), $second->getKey());
    expect(favoriteOrder($this->user, $this->workspace))->toBe([$first->getKey(), $favorite->getKey(), $second->getKey()]);
});

it('keeps a hidden favorite in its folder and place while another moves', function (): void {
    $folder = favoriteFolderFor($this->user, $this->workspace, 'Q4 renewals', 1);
    $hidden = movedFavorite(1, $folder);
    $hidden->record->delete();
    $visible = movedFavorite(2, $folder);

    Livewire::test(FavoritesSidebarNav::class)->call('move', $visible->getKey(), $folder->getKey(), null, null);

    expect(favoriteOrder($this->user, $this->workspace, $folder))->toBe([$hidden->getKey(), $visible->getKey()]);
});

it('drops a neighbour from another list and places the favorite at the end', function (): void {
    $folder = favoriteFolderFor($this->user, $this->workspace, 'Q4 renewals', 1);
    $elsewhere = movedFavorite(1, $folder);
    [$a, $b] = [movedFavorite(2), movedFavorite(3)];

    Livewire::test(FavoritesSidebarNav::class)->call('move', $a->getKey(), null, $b->getKey(), $elsewhere->getKey());

    expect(favoriteOrder($this->user, $this->workspace))->toBe([$folder->getKey(), $b->getKey(), $a->getKey()]);
});

it('places a favorite after its first neighbour when another tab reordered the two', function (): void {
    [$a, $b, $moved] = [movedFavorite(1), movedFavorite(2), movedFavorite(3)];

    Livewire::test(FavoritesSidebarNav::class)
        ->call('move', $moved->getKey(), null, $b->getKey(), $a->getKey())
        ->assertDispatched('refresh-sidebar');

    expect(favoriteOrder($this->user, $this->workspace))->toBe([$a->getKey(), $b->getKey(), $moved->getKey()]);
});

it('keeps a favorite directly after its first neighbour when another tab reordered the two and a sibling follows', function (): void {
    [$a, $b, $c, $moved] = [movedFavorite(1), movedFavorite(2), movedFavorite(3), movedFavorite(4)];

    Livewire::test(FavoritesSidebarNav::class)
        ->call('move', $moved->getKey(), null, $b->getKey(), $a->getKey());

    expect(favoriteOrder($this->user, $this->workspace))->toBe([$a->getKey(), $b->getKey(), $moved->getKey(), $c->getKey()]);
});

it('rebalances the list when two neighbours leave no room between them', function (): void {
    [$a, $b] = [movedFavorite(1), movedFavorite(2)];
    $b->update(['position' => '1.00005']);
    $moved = movedFavorite(3);

    Livewire::test(FavoritesSidebarNav::class)->call('move', $moved->getKey(), null, $a->getKey(), $b->getKey());

    expect(favoriteOrder($this->user, $this->workspace))->toBe([$a->getKey(), $moved->getKey(), $b->getKey()])
        ->and(bcsub($b->fresh()->position, $a->fresh()->position, 10))->not->toBe('0.0000500000');
});

it('never puts a folder inside a folder', function (): void {
    $outer = favoriteFolderFor($this->user, $this->workspace, 'Outer', 1);
    $inner = favoriteFolderFor($this->user, $this->workspace, 'Inner', 2);

    Livewire::test(FavoritesSidebarNav::class)
        ->call('move', $inner->getKey(), $outer->getKey(), null, null)
        ->assertForbidden();

    expect($inner->fresh()->parent_id)->toBeNull();
});

it("cannot move another member's favorite", function (): void {
    $other = User::factory()->create();
    $this->workspace->users()->attach($other, ['role' => 'admin']);
    $theirs = favoriteFor($other, $this->workspace, Company::factory()->recycle([$this->user, $this->workspace])->create(), 1);
    $mine = movedFavorite(1);

    Livewire::test(FavoritesSidebarNav::class)
        ->call('move', $theirs->getKey(), null, $mine->getKey(), null)
        ->assertNotFound();

    expect($theirs->fresh()->position)->toBe($theirs->position);
});

it('refuses a member outside the workspace', function (): void {
    $this->actingAs(User::factory()->withWorkspace()->create());

    Livewire::test(FavoritesSidebarNav::class)
        ->call('move', 'missing', null, null, null)
        ->assertForbidden();
});
