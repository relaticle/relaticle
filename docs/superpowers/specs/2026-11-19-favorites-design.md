# Favorites: pin companies, people and opportunities to the sidebar

**Date:** 2026-11-19
**Status:** Built on `favorites-pin-companies-people-and-opportunities`, except the two rules marked below. Revises issue #1194, which still holds the first version

## Summary

Members work the same handful of records every day. Favorites put those records one click away in the
sidebar. A member stars a record from its page, groups favorites into folders, and drags them into
order. Favorites are personal: one member, one workspace.

## Decisions (2026-11-19)

Taken after a review against Attio's favorites. Each one changes the spec in #1194.

| # | Question | Choice |
|---|---|---|
| 1 | When the sidebar group shows | While the member has a favorite or a folder. With neither, it is hidden. The empty hint is gone |
| 2 | When a folder can be created | Once the group shows, after the first favorite. Removing every favorite keeps the folders, and the group stays |
| 3 | The record header's "..." menu | Holds **Add to favorites**, **Move favorite to** and **Remove from favorites**, above **Copy page URL** |
| 4 | Where **Move favorite to** shows | On a favorite, while the member has a folder. Its submenu lists **No folder**, then each folder in sidebar order, and checks the current place |
| 5 | Where a moved favorite lands | At the end of the chosen folder, or at the end of the top level |
| 5a | **Add to favorites** with folders | Opens a folder submenu, **No folder** first, like Attio. The header star still adds to the top level |
| 6 | The folder modal | Opens in the center of the page, not inside the sidebar |
| 7 | The group's new-folder button | 16px, the size of the sidebar's other icons, and no taller than the group header |
| 8 | An empty folder | Shows "No items" while open, as Attio does. The row is also the drop target, so a favorite can be dragged into an empty folder |
| 9 | Storage | One `favorites` table. `kind` (`record` or `folder`) says which; a folder has a `name`, a favorite has `record_type` and `record_id`, and a check constraint holds that shape. `parent_id` points at the folder |
| 10 | Order | `position` is `decimal(20,10)`, ordered by Flowforge's `DecimalPosition`, the same scheme the boards use. A drag writes one row: the midpoint of its new neighbours, or one gap past the last sibling |

## What a member sees

### Favoriting a record

- **Add to favorites** (`lucide-star`) sits in the header of the company, person and opportunity record
  pages. On a favorite the star is filled and the action reads **Remove from favorites**.
- The header's "..." menu repeats it. On a record that is not a favorite it offers **Add to favorites**.
  When the member has a folder, **Add to favorites** opens the same folder submenu as **Move favorite to**,
  and the favorite lands at the end of the chosen folder or of the top level.
  On a favorite it offers **Move favorite to** (`lucide-star`), when a folder exists, and **Remove from
  favorites** (`lucide-star-off`).
- **Move favorite to** opens a submenu to its right. **No folder** (`lucide-square-slash`) comes first,
  then each folder (`lucide-folder`). The current place carries the menu check.
- A new favorite lands at the end of the group's top level. It never goes into a folder on its own.
- Each entry shows the record's name, its entity icon and a label (Company, Person, Opportunity).
  Clicking it opens the record page. A `lucide-star-off` button shows on hover and removes it.

### Folders

- The group header carries a `lucide-folder-plus` button. It opens a modal in the center of the page
  that asks for a name. Saving creates an empty folder.
- A member drags a favorite into a folder, out of it, or into another folder. Dragging also reorders
  favorites and folders, and the order is kept.
- While dragging, a dashed line in the primary color marks where the row will land.
- A folder's menu has **Rename**, which opens the same modal, and **Delete folder**.
- Folders are one level deep. Deleting a folder moves its favorites to the end of the top level.
- An open empty folder shows "No items", and a favorite dropped on it moves into the folder.

### Layout

- The group shows while the member has a favorite or a folder, and hides when they have neither.
- With the sidebar collapsed, the group shows icons only. On phones it sits in the sidebar drawer, and
  the folder modal opens as a sheet over the whole page.

## Rules

- Favorites and folders belong to one member in one workspace. Nobody else sees them.
- A deleted record leaves the group. A restored record comes back to the same place.
- When two records are merged (#1193), favorites of the removed record move to the kept one.
  **Not built yet:** nothing in the merge touches favorites.
- A member who loses access to a record no longer sees its favorite. **Not built yet:**
  `FavoritesQuery` filters by owner and workspace only, and checks no policy.

## How it is built

| Piece | Owner |
|---|---|
| Storage and order | `App\Models\Favorite`, `App\Enums\FavoriteKind`, `Favorite::nextPosition()` |
| Add, remove a favorite | `AddFavoriteRecord`, `RemoveFavoriteRecord` in `app/Actions/Favorites` |
| Move by menu or drag | `MoveFavorite`: into a folder, out of one, or between two neighbours. It rebalances the list when two neighbours leave no room |
| Create, rename, delete a folder | `CreateFavoriteFolder`, `RenameFavoriteFolder`, `DeleteFavoriteFolder`. `Favorite::folderName()` holds the name rule |
| A record's favorite | `Favorite::forRecord()` scope and `Favorite::ownedFor()`, memoized per request and cleared on every favorites write |
| Header star | `App\Filament\Actions\ToggleFavoriteAction` |
| Header "..." menu items | `App\Filament\Actions\FavoriteMenuActions` |
| Sidebar group | `App\Livewire\App\FavoritesSidebarNav`, rendered at `SIDEBAR_NAV_END` |

Four Livewire and CSS facts shaped the build:

- **A favorite's link is not `wire:navigate`.** Livewire cancels `mousedown` on such a link, so the
  browser never starts a drag, and it navigates on `mouseup`, so a drop opened the record. The link
  calls `Livewire.navigate()` on a plain click instead. A drag fires no click.

- **The sidebar repaints only through its parent.** Every change dispatches `refresh-sidebar`. Livewire
  sends a stub for a child whose key it rendered before, so the hook mounts the component under a fresh
  key on each render. A fixed key left the sidebar stale after the second change on a page.
- **The record page re-renders on `favorites-updated`.** The sidebar dispatches it after every folder
  or favorite change, so the "..." menu lists a new folder without a reload.
- **The open sidebar sets `translate: none`.** Filament's `translate-x-0` moves nothing, but any
  translate makes the sidebar the containing block of its fixed children. The folder modal opened
  inside the sidebar because of it. Teleporting the modal instead breaks Filament's `wire:partial`
  modal updates.

## Out of scope

- Tasks and notes, which have no record page
- Saved table views (#943)
- Favorites shared with the workspace
- Nested folders
- Rela, the MCP server and the REST API. Favorites are personal navigation, not CRM data
- Following and notifications, which #1012 covers

## Tests

- `tests/Feature/Favorites/ToggleFavoriteTest.php`: the header star, and the "..." menu's add, move and
  remove, including a folder created after the page loaded
- `tests/Feature/Favorites/FavoritesSidebarTest.php`: the group, its visibility rule, trashed and
  restored records, and other members' favorites
- `tests/Feature/Favorites/FavoriteFoldersTest.php`: folder create, rename and delete
- `tests/Feature/Favorites/MoveFavoriteTest.php`: moves between neighbours, into and out of folders, rebalancing, and ownership
- `ToggleFavoriteTest` also pins a record page to two favorites queries per render. Before the memo it ran 101 with five folders
- `tests/Browser/Panel/FavoritesDragTest.php`: a real drag into an empty folder and back out, in
  Chromium and WebKit. It fails if the link goes back to `wire:navigate`. It also opens a folder's
  menu and fails when the panel leaves its button: a transform on the menu's wrapper once placed
  it off screen, because a transformed ancestor becomes the reference box of a fixed panel
